import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { afterEach, beforeEach, test } from 'node:test';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import { createRenderer, h, nextTick } from 'vue';

async function loadComponent(name) {
    const filename = new URL(`../../resources/js/shared/${name}.vue`, import.meta.url);
    const source = await readFile(filename, 'utf8');
    const { descriptor } = parse(source);
    const { content } = compileScript(descriptor, {
        id: name, inlineTemplate: true, templateOptions: { compilerOptions: { hoistStatic: false } },
    });
    const module = content.replaceAll('from "vue"', `from '${import.meta.resolve('vue')}'`)
        .replaceAll("from 'vue'", `from '${import.meta.resolve('vue')}'`);

    return (await import(`data:text/javascript;base64,${Buffer.from(module).toString('base64')}`)).default;
}

const originalGlobals = Object.fromEntries(['window', 'document', 'localStorage', 'fetch'].map(key => [key, globalThis[key]]));
globalThis.window = new EventTarget();
const CookieConsentBanner = await loadComponent('CookieConsentBanner');
const AuthLegalNotice = await loadComponent('AuthLegalNotice');
const BuyerFooter = await loadComponent('../buyer/components/Footer');
const homeSource = await readFile(new URL('../../resources/js/home/components/Home.vue', import.meta.url), 'utf8');
const homeFooterSource = parse(homeSource).descriptor.template.content.match(/<footer\b[\s\S]*?<\/footer>/)[0];
const homeFooterTemplate = compileTemplate({
    source: homeFooterSource, filename: 'Home.vue', id: 'HomeFooter', compilerOptions: { hoistStatic: false },
});
assert.deepEqual(homeFooterTemplate.errors, []);
const homeFooterCode = homeFooterTemplate.code.replaceAll('from "vue"', `from '${import.meta.resolve('vue')}'`);
const HomeFooter = {
    props: ['logoUrl'],
    render: (await import(`data:text/javascript;base64,${Buffer.from(homeFooterCode).toString('base64')}`)).render,
};
let storage;
let apps;

const renderer = createRenderer({
    createElement: type => ({ type, tagName: type.toUpperCase(), props: {}, children: [], addEventListener() {}, removeEventListener() {} }),
    createText: text => ({ text, children: [] }),
    createComment: () => ({ children: [] }),
    setText: (node, text) => { node.text = text; },
    setElementText: (node, text) => { node.text = text; node.children = []; },
    patchProp: (node, key, previous, value) => { node.props[key] = value; node[key] = value; },
    insert(node, parent, anchor) {
        if (node.parent) {
            node.parent.children.splice(node.parent.children.indexOf(node), 1);
        }
        const index = anchor ? parent.children.indexOf(anchor) : -1;
        parent.children.splice(index < 0 ? parent.children.length : index, 0, node);
        node.parent = parent;
    },
    remove: node => { node.parent.children.splice(node.parent.children.indexOf(node), 1); },
    parentNode: node => node.parent,
    nextSibling: node => node.parent?.children[node.parent.children.indexOf(node) + 1],
});

function mount(component, props = {}) {
    const root = { children: [] };
    const app = renderer.createApp({ render: () => h(component, props) });
    app.mount(root);
    apps.push(app);

    return root;
}

function find(root, predicate) {
    if (predicate(root)) {
        return root;
    }
    for (const child of root.children) {
        const result = find(child, predicate);
        if (result) {
            return result;
        }
    }

    return null;
}

const button = (root, label) => find(root, node => node.type === 'button' && node.text === label);
const banner = root => find(root, node => node.props?.role === 'dialog');
const settle = async () => { await new Promise(resolve => setImmediate(resolve)); await nextTick(); };

function textContent(node) {
    return (node.text || '') + node.children.map(textContent).join(' ');
}

beforeEach(() => {
    storage = new Map();
    apps = [];
    globalThis.window = new EventTarget();
    globalThis.document = { documentElement: { lang: 'en-PH' } };
    globalThis.localStorage = {
        getItem: key => storage.get(key) ?? null,
        setItem: (key, value) => storage.set(key, value),
    };
    globalThis.fetch = async () => ({ ok: true });
});

afterEach(() => {
    apps.forEach(app => app.unmount());
    Object.assign(globalThis, originalGlobals);
});

test('login and signup display working legal links below the form with appropriate wording', () => {
    for (const mode of ['login', 'signup']) {
        const root = mount(AuthLegalNotice, { mode });
        const terms = find(root, node => node.type === 'a' && node.text === 'Terms of Service');
        const privacy = find(root, node => node.type === 'a' && node.text === 'Privacy Policy');
        assert.equal(terms.props.href, '/terms');
        assert.equal(privacy.props.href, '/privacy');
        assert.equal(terms.props.target, '_blank');
        assert.match(privacy.props.rel, /noopener/);
        assert.ok(find(root, node => node.text?.includes(mode === 'signup' ? 'signing up' : 'logging in')));
    }
});

test('public and buyer footers omit the removed contact placeholders and links', () => {
    for (const component of [HomeFooter, BuyerFooter]) {
        const root = mount(component, { logoUrl: '/images/logo.png' });
        const text = textContent(root);
        assert.doesNotMatch(text, /Data Protection Officer|NPC Registration|DTI Trustmark|Cookie Preferences|DTI Online Dispute Resolution|\[DPO_EMAIL\]/);
        assert.ok(find(root, node => node.type === 'a' && node.props.href === '/privacy'));
        assert.ok(find(root, node => node.type === 'a' && node.props.href === '/terms'));
        assert.ok(find(root, node => node.type === 'a' && node.props.href === '/cookies'));
    }
});

test('a first visit displays cookie choices without requiring login', async () => {
    const root = mount(CookieConsentBanner);
    await nextTick();
    assert.ok(banner(root));
    assert.ok(button(root, 'Accept All'));
    assert.ok(button(root, 'Reject Non-Essential'));
    assert.equal(storage.has('btw.cookie-consent'), false);
});

test('consent is saved on the server before the banner closes and remembered on subsequent visits', async () => {
    let finish;
    globalThis.fetch = (url, options) => {
        assert.equal(url, '/api/consent/cookies');
        assert.equal(JSON.parse(options.body).categories.marketing, true);
        return new Promise(resolve => { finish = resolve; });
    };
    const root = mount(CookieConsentBanner);
    button(root, 'Accept All').props.onClick();
    await nextTick();
    assert.ok(banner(root));
    assert.equal(storage.has('btw.cookie-consent'), false);
    assert.equal(button(root, 'Accept All').props.disabled, true);
    finish({ ok: true });
    await settle();
    assert.equal(banner(root), null);
    assert.equal(JSON.parse(storage.get('btw.cookie-consent')).marketing, true);
    const returningVisit = mount(CookieConsentBanner);
    await nextTick();
    assert.equal(banner(returningVisit), null);
});

test('rejecting optional cookies keeps necessary cookies enabled and preferences can be reopened', async () => {
    const root = mount(CookieConsentBanner);
    button(root, 'Reject Non-Essential').props.onClick();
    await settle();
    assert.deepEqual(JSON.parse(storage.get('btw.cookie-consent')), {
        strictly_necessary: true, functional: false, analytics: false, marketing: false,
    });
    window.dispatchEvent(new Event('btw:open-cookie-preferences'));
    await nextTick();
    assert.ok(banner(root));
    assert.ok(button(root, 'Save Preferences'));
    const necessary = find(root, node => node.tagName === 'INPUT' && Object.hasOwn(node.props, 'disabled'));
    assert.ok(necessary);
    assert.notEqual(necessary.props.checked, false);
    assert.ok(Object.hasOwn(necessary.props, 'checked'));
    assert.notEqual(necessary.props.disabled, false);
});

test('corrupt or incomplete consent does not hide the first-visit banner', async () => {
    for (const saved of ['invalid json', 'null', '{}', '{"strictly_necessary":true}', '{"strictly_necessary":false,"functional":false,"analytics":false,"marketing":false}']) {
        storage.set('btw.cookie-consent', saved);
        const root = mount(CookieConsentBanner);
        await nextTick();
        assert.ok(banner(root), saved);
    }
});

test('failed consent saves keep the banner open and allow retry without enabling optional cookies', async () => {
    for (const failure of ['network', 'server']) {
        globalThis.fetch = async () => {
            if (failure === 'network') {
                throw new Error('Offline');
            }
            return { ok: false };
        };
        const root = mount(CookieConsentBanner);
        button(root, 'Accept All').props.onClick();
        await settle();
        assert.ok(banner(root));
        assert.ok(find(root, node => node.props?.role === 'alert'));
        assert.equal(button(root, 'Accept All').props.disabled, false);
        assert.equal(storage.has('btw.cookie-consent'), false);
        apps.pop().unmount();
    }
    globalThis.fetch = async () => ({ ok: true });
    const root = mount(CookieConsentBanner);
    button(root, 'Reject Non-Essential').props.onClick();
    await settle();
    assert.equal(banner(root), null);
});

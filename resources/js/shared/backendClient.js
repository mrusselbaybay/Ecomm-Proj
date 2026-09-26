// Client for the app's own Laravel backend: auth (Sanctum tokens), file
// storage and realtime. It keeps the call shapes the portals were written
// against — `client.auth.getSession()`, `client.storage.from(bucket)...`,
// `{ data, error }` results — so replacing the previous hosted backend didn't
// require rewriting every call site.
//
// One shared instance per page (see createClient()).

const STORAGE_KEY = 'btw.auth.session';

function readStored() {
    try {
        const raw = window.localStorage.getItem(STORAGE_KEY);

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function writeStored(session) {
    try {
        if (session) {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(session));
        } else {
            window.localStorage.removeItem(STORAGE_KEY);
        }
    } catch {
        // Storage blocked (private mode): the session just won't persist.
    }
}

function isExpired(session) {
    return !session?.access_token || (session.expires_at && session.expires_at * 1000 <= Date.now());
}

function toError(payload, status) {
    const message =
        Object.values(payload?.errors || {})[0]?.[0] || payload?.message || 'The request failed.';

    return Object.assign(new Error(message), { status, payload });
}

async function request(path, { method = 'GET', body, token, form } = {}) {
    const headers = { Accept: 'application/json' };

    if (token) {
        headers.Authorization = `Bearer ${token}`;
    }

    if (body !== undefined && !form) {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(path, {
        method,
        headers,
        body: form ?? (body !== undefined ? JSON.stringify(body) : undefined),
    });
    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw toError(payload, response.status);
    }

    return payload;
}

function createAuth() {
    let session = readStored();
    const listeners = new Set();

    if (isExpired(session)) {
        session = null;
        writeStored(null);
    }

    function emit(event) {
        listeners.forEach((cb) => {
            try {
                cb(event, session);
            } catch (error) {
                console.error(error);
            }
        });
    }

    function setSession(next, event) {
        session = next;
        writeStored(next);
        emit(event);
    }

    // Sign-in/out in another tab.
    window.addEventListener('storage', (e) => {
        if (e.key !== STORAGE_KEY) {
            return;
        }

        const next = readStored();
        const event = next ? 'SIGNED_IN' : 'SIGNED_OUT';
        session = next;
        emit(event);
    });

    // Google sign-in lands back with the token in the URL fragment.
    const hashLogin = (async () => {
        const params = new URLSearchParams(window.location.hash.slice(1));
        const token = params.get('access_token');

        if (!token) {
            return;
        }

        history.replaceState(null, '', window.location.pathname + window.location.search);

        try {
            const user = await request('/api/auth/user', { token });
            setSession(
                {
                    access_token: token,
                    refresh_token: token,
                    token_type: 'bearer',
                    expires_in: Number(params.get('expires_in')) || null,
                    expires_at: Number(params.get('expires_at')) || null,
                    user,
                },
                'SIGNED_IN',
            );
        } catch (error) {
            console.error('Could not complete the redirect sign-in:', error);
        }
    })();

    return {
        async getSession() {
            await hashLogin;

            if (isExpired(session)) {
                session = null;
                writeStored(null);
            }

            return { data: { session }, error: null };
        },

        async getUser() {
            await hashLogin;

            if (isExpired(session)) {
                return { data: { user: null }, error: Object.assign(new Error('Auth session missing!'), { status: 401 }) };
            }

            try {
                const user = await request('/api/auth/user', { token: session.access_token });
                session = { ...session, user };
                writeStored(session);

                return { data: { user }, error: null };
            } catch (error) {
                if (error.status === 401) {
                    setSession(null, 'SIGNED_OUT');
                }

                return { data: { user: null }, error };
            }
        },

        // Tokens are long-lived; "refreshing" re-validates the current one.
        async refreshSession() {
            const { data, error } = await this.getUser();

            return { data: { session: data.user ? session : null, user: data.user }, error };
        },

        async signInWithPassword({ email, password }) {
            try {
                const previous = session?.access_token;
                const next = await request('/api/auth/login', { method: 'POST', body: { email, password } });

                setSession(next, 'SIGNED_IN');

                // Re-verifying a password while signed in shouldn't leave the old token behind.
                if (previous && previous !== next.access_token) {
                    request('/api/auth/logout', { method: 'POST', token: previous }).catch(() => {});
                }

                return { data: { user: next.user, session: next }, error: null };
            } catch (error) {
                return { data: { user: null, session: null }, error };
            }
        },

        async signOut() {
            const token = session?.access_token;
            setSession(null, 'SIGNED_OUT');

            if (token) {
                await request('/api/auth/logout', { method: 'POST', token }).catch(() => {});
            }

            return { error: null };
        },

        async updateUser(attributes) {
            if (isExpired(session)) {
                return { data: { user: null }, error: new Error('Auth session missing!') };
            }

            try {
                const user = await request('/api/auth/user', {
                    method: 'PUT',
                    body: attributes,
                    token: session.access_token,
                });
                setSession({ ...session, user }, 'USER_UPDATED');

                return { data: { user }, error: null };
            } catch (error) {
                return { data: { user: null }, error };
            }
        },

        onAuthStateChange(callback) {
            listeners.add(callback);
            hashLogin.then(() => callback('INITIAL_SESSION', session));

            return { data: { subscription: { unsubscribe: () => listeners.delete(callback) } } };
        },

        token() {
            return isExpired(session) ? null : session.access_token;
        },

        userId() {
            return isExpired(session) ? null : (session.user?.id ?? null);
        },
    };
}

function createStorage(auth) {
    return {
        from(bucket) {
            const base = `/api/storage/${encodeURIComponent(bucket)}`;

            return {
                async upload(path, file, options = {}) {
                    const form = new FormData();
                    form.append('path', path);
                    form.append('file', file);

                    if (options.upsert) {
                        form.append('upsert', '1');
                    }

                    try {
                        const data = await request(base, { method: 'POST', form, token: auth.token() });

                        return { data, error: null };
                    } catch (error) {
                        return { data: null, error };
                    }
                },

                async createSignedUrl(path, expiresIn = 300) {
                    try {
                        const params = new URLSearchParams({ path, expires_in: String(expiresIn) });
                        const data = await request(`${base}/signed-url?${params}`, { token: auth.token() });

                        return { data: { signedUrl: data.signedUrl }, error: null };
                    } catch (error) {
                        return { data: null, error };
                    }
                },

                getPublicUrl(path) {
                    return { data: { publicUrl: publicFileUrl(bucket, path) } };
                },
            };
        },
    };
}

/** URL of a file in one of the public buckets (avatars, product images, ...). */
export function publicFileUrl(bucket, path) {
    if (!path) {
        return null;
    }

    return `/storage/${bucket}/${String(path).split('/').map(encodeURIComponent).join('/')}`;
}

let instance = null;

/** The page-wide client (auth state must be shared by every caller). */
export function createClient() {
    if (!instance) {
        const auth = createAuth();
        instance = { auth, storage: createStorage(auth) };
    }

    return instance;
}

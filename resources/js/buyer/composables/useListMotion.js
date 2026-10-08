// resources/js/buyer/composables/useListMotion.js
//
// For <TransitionGroup name="lx" class="lx-group" @before-leave="freezeLeaving">:
// a removed item is lifted out of the layout at exactly the size and place
// it had, so the items after it can slide into the gap (the .lx-move FLIP)
// while it fades out — instead of the whole list or grid snapping shut.
// The group needs position: relative (.lx-group). Styles in layout.css.

export function freezeLeaving(el) {
    const { offsetLeft, offsetTop, offsetWidth, offsetHeight } = el;

    el.style.position = 'absolute';
    el.style.left = `${offsetLeft}px`;
    el.style.top = `${offsetTop}px`;
    el.style.width = `${offsetWidth}px`;
    el.style.height = `${offsetHeight}px`;
    el.style.margin = '0';
}

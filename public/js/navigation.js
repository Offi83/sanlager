/*
 * Auf schmalen Bildschirmen ist die Navigation seitlich wischbar
 * (siehe app.css). Damit der aktive Menüpunkt dort nicht außerhalb des
 * sichtbaren Bereichs liegt, wird er beim Laden in die Mitte gescrollt.
 */

document.addEventListener('DOMContentLoaded', function () {

    const nav =
        document.querySelector('nav');

    const active =
        nav && nav.querySelector('a.active');

    if (!active || nav.scrollWidth <= nav.clientWidth) {
        return;
    }

    const offset =
        active.getBoundingClientRect().left
        - nav.getBoundingClientRect().left;

    nav.scrollLeft +=
        offset - (nav.clientWidth - active.offsetWidth) / 2;

});

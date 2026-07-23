/**
 * Dotly Site — UI Core
 */

document.addEventListener("DOMContentLoaded", function () {
  initSmoothScroll();
  initFadeInObserver();
  initTableScrollMasks();
});

function initSmoothScroll() {
  document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
    anchor.addEventListener("click", function (e) {
      var href = this.getAttribute("href");
      if (href !== "#" && document.querySelector(href)) {
        e.preventDefault();
        document
          .querySelector(href)
          .scrollIntoView({ behavior: "smooth", block: "start" });
      }
    });
  });
}

// Fade-in sections as they enter viewport
function initFadeInObserver() {
  var targets = document.querySelectorAll(".animate-fadein");
  if (!targets.length || !("IntersectionObserver" in window)) {
    targets.forEach(function (el) {
      el.classList.remove("animate-pending");
    });
    return;
  }
  var observer = new IntersectionObserver(
    function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.remove("animate-pending");
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.12 },
  );
  targets.forEach(function (el) {
    observer.observe(el);
  });
}

// Table scroll mask — remove mask when no overflow or scroll reached end
function initTableScrollMasks() {
  document
    .querySelectorAll(".ranking-table-wrap:not(.ranking-table-preview)")
    .forEach(function (wrap) {
      function update() {
        var overflows = wrap.scrollWidth > wrap.clientWidth + 2;
        var atEnd = wrap.scrollLeft + wrap.clientWidth >= wrap.scrollWidth - 4;
        if (!overflows || atEnd) {
          wrap.classList.add("scrolled-end");
        } else {
          wrap.classList.remove("scrolled-end");
        }
      }
      update();
      wrap.addEventListener("scroll", update, { passive: true });
      window.addEventListener("resize", update, { passive: true });
    });
}

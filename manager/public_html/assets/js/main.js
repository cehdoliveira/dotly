/**
 * Dotly - UI Core
 */

document.addEventListener("DOMContentLoaded", function () {
  initializeTheme();
  initializeSmoothScroll();
});

function initializeSmoothScroll() {
  document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
    anchor.addEventListener("click", function (e) {
      const href = this.getAttribute("href");
      if (href !== "#" && document.querySelector(href)) {
        e.preventDefault();
        document
          .querySelector(href)
          .scrollIntoView({ behavior: "smooth", block: "start" });
      }
    });
  });
}

function initializeTheme() {
  const storageKey = "theme";
  const root = document.documentElement;

  const applyTheme = (theme) => {
    const isDark = theme === "dark";
    root.setAttribute("data-theme", isDark ? "dark" : "light");

    document.querySelectorAll("[data-theme-toggle]").forEach((button) => {
      button.innerHTML = isDark
        ? '<i class="bi bi-sun"></i><span class="d-none d-md-inline ms-1">Claro</span>'
        : '<i class="bi bi-moon-stars"></i><span class="d-none d-md-inline ms-1">Escuro</span>';
      button.setAttribute(
        "aria-label",
        isDark ? "Ativar tema claro" : "Ativar tema escuro",
      );
    });
  };

  const saved =
    localStorage.getItem(storageKey) ||
    (window.matchMedia("(prefers-color-scheme: dark)").matches
      ? "dark"
      : "light");
  localStorage.setItem(storageKey, saved);
  applyTheme(saved);

  document.addEventListener("click", function (event) {
    const button = event.target.closest("[data-theme-toggle]");
    if (!button) {
      return;
    }

    const isDark = root.getAttribute("data-theme") === "dark";
    const nextTheme = isDark ? "light" : "dark";
    localStorage.setItem(storageKey, nextTheme);
    applyTheme(nextTheme);
  });
}

function injectFloatingThemeToggle() {
  if (document.querySelector(".dotly-theme-toggle-floating")) {
    return;
  }
  const floatingButton = document.createElement("button");
  floatingButton.type = "button";
  floatingButton.className =
    "btn btn-sm dotly-theme-toggle dotly-theme-toggle-floating";
  floatingButton.setAttribute("data-theme-toggle", "true");
  floatingButton.setAttribute("title", "Alternar tema");
  floatingButton.setAttribute("aria-label", "Ativar tema claro");
  floatingButton.innerHTML =
    '<i class="bi bi-sun"></i><span class="d-none d-md-inline">Claro</span>';
  document.body.appendChild(floatingButton);
}

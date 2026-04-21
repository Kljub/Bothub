// # PFAD: /assets/js/landing.js
(function () {
  "use strict";

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }
  function qsa(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  var loginModal = qs("#bhAuthLogin");
  var registerModal = qs("#bhAuthRegister");

  function isOpen(modal) {
    return modal && modal.classList.contains("is-open");
  }

  function open(modal) {
    if (!modal) return;
    modal.classList.add("is-open");
    modal.setAttribute("aria-hidden", "false");
    document.body.style.overflow = "hidden";

    window.setTimeout(function () {
      var first = qs("input,button,select,textarea", modal);
      if (first) first.focus();
    }, 30);
  }

  function close(modal) {
    if (!modal) return;
    modal.classList.remove("is-open");
    modal.setAttribute("aria-hidden", "true");

    if (!isOpen(loginModal) && !isOpen(registerModal)) {
      document.body.style.overflow = "";
    }
  }

  function closeAll() {
    close(loginModal);
    close(registerModal);
  }

  qsa("[data-bh-open]").forEach(function (el) {
    el.addEventListener("click", function (e) {
      e.preventDefault();
      var t = el.getAttribute("data-bh-open") || "login";

      closeAll();
      if (t === "register") open(registerModal);
      else open(loginModal);
    });
  });

  [loginModal, registerModal].forEach(function (modal) {
    if (!modal) return;

    qsa("[data-bh-close]", modal).forEach(function (el) {
      el.addEventListener("click", function (e) {
        e.preventDefault();
        close(modal);
      });
    });
  });

  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape") closeAll();
  });

  // Auto-open on load (for redirects after auth)
  function openFromUrlOrBody() {
    var openTarget = "";

    try {
      var params = new URLSearchParams(window.location.search || "");
      openTarget = (params.get("auth") || params.get("tab") || "").toLowerCase();
    } catch (e) {
      openTarget = "";
    }

    if (!openTarget) {
      var b = document.body;
      if (b && b.getAttribute("data-bh-open-onload")) {
        openTarget = (b.getAttribute("data-bh-open-onload") || "").toLowerCase();
      }
    }

    if (openTarget === "register") {
      closeAll();
      open(registerModal);
      return;
    }

    if (openTarget === "login") {
      closeAll();
      open(loginModal);
      return;
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", openFromUrlOrBody);
  } else {
    openFromUrlOrBody();
  }
})();
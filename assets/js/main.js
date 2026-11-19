(() => {
  if (typeof GLightbox !== "undefined") {
    GLightbox({
      selector: ".js-glightbox",
      touchNavigation: true,
      loop: true,
    });
  }

  document.querySelectorAll(".js-confirm").forEach((button) => {
    button.addEventListener("click", (event) => {
      const message = button.getAttribute("data-confirm") || "Proceed with this action?";
      if (!window.confirm(message)) {
        event.preventDefault();
      }
    });
  });

  initHomeAnimations();
  initOnlineUpdateBridge();
})();

function initOnlineUpdateBridge() {
  const panel = document.createElement("aside");
  panel.className = "online-update-panel is-online";
  panel.setAttribute("role", "status");
  panel.setAttribute("aria-live", "polite");
  panel.innerHTML = `
    <span class="online-dot" aria-hidden="true"></span>
    <div class="online-copy">
      <p class="online-title">Live Status</p>
      <p class="online-subtitle">Online and synced</p>
      <p class="online-meta">Last checked: now</p>
    </div>
    <button class="online-refresh-btn" type="button" hidden>Refresh</button>
  `;
  document.body.appendChild(panel);

  const subtitle = panel.querySelector(".online-subtitle");
  const meta = panel.querySelector(".online-meta");
  const refreshButton = panel.querySelector(".online-refresh-btn");
  let minimizeTimer = null;

  const clearMinimizeTimer = () => {
    if (minimizeTimer) {
      window.clearTimeout(minimizeTimer);
      minimizeTimer = null;
    }
  };

  const scheduleMinimize = () => {
    clearMinimizeTimer();
    minimizeTimer = window.setTimeout(() => {
      panel.classList.add("is-minimized");
    }, 5000);
  };

  const expandPanel = () => {
    panel.classList.remove("is-minimized");
    scheduleMinimize();
  };

  const setMode = (mode, message) => {
    panel.classList.remove("is-online", "is-offline", "is-checking", "is-update-ready");
    panel.classList.add(mode);
    if (subtitle) subtitle.textContent = message;
    if (refreshButton) refreshButton.hidden = mode !== "is-update-ready";
    if (mode === "is-update-ready" || mode === "is-offline") {
      clearMinimizeTimer();
      panel.classList.remove("is-minimized");
      return;
    }
    scheduleMinimize();
  };

  const setLastCheckedNow = () => {
    if (meta) meta.textContent = "Last checked: just now";
  };

  const updateUrl = new URL("/api/online-update.php", window.location.origin).toString();
  const debugUpdate = new URLSearchParams(window.location.search).get("debug_update") === "1";
  const debugLog = (...args) => {
    if (debugUpdate) {
      console.info("[online-update]", ...args);
    }
  };
  debugLog("resolved update URL:", updateUrl);
  let knownVersion = window.sessionStorage.getItem("online_update_version") || "";
  let pendingReload = false;

  const scheduleReload = () => {
    if (pendingReload) {
      return;
    }
    pendingReload = true;
    setMode("is-checking", "Applying update...");
    window.setTimeout(() => {
      window.location.reload();
    }, 900);
  };

  const checkForUpdate = () => {
    if (!navigator.onLine) {
      return;
    }
    setMode("is-checking", "Checking for updates...");

    fetch(updateUrl, { cache: "no-store", credentials: "same-origin" })
      .then((response) => {
        debugLog("response status:", response.status);
        if (!response.ok) {
          return null;
        }
        return response.json();
      })
      .then((payload) => {
        debugLog("payload:", payload);
        if (!payload || !payload.version) {
          setMode("is-online", "Online");
          return;
        }

        const nextVersion = String(payload.version);
        setLastCheckedNow();
        if (!knownVersion) {
          knownVersion = nextVersion;
          window.sessionStorage.setItem("online_update_version", nextVersion);
          setMode("is-online", "Online and synced");
          return;
        }

        if (nextVersion !== knownVersion) {
          window.sessionStorage.setItem("online_update_version", nextVersion);
          setMode("is-update-ready", "Update available");
          return;
        }
        setMode("is-online", "Online and synced");
      })
      .catch(() => {
        debugLog("request failed");
        setMode("is-offline", "Update check failed");
      });
  };

  checkForUpdate();
  window.setInterval(checkForUpdate, 45000);

  window.addEventListener("offline", () => {
    setMode("is-offline", "Offline. Waiting for reconnect.");
  });

  window.addEventListener("online", () => {
    setMode("is-online", "Reconnected");
    checkForUpdate();
  });

  if (refreshButton) {
    refreshButton.addEventListener("click", scheduleReload);
  }

  panel.addEventListener("click", () => {
    if (panel.classList.contains("is-minimized")) {
      expandPanel();
    }
  });

  if (!navigator.onLine) {
    setMode("is-offline", "Offline. Waiting for reconnect.");
  } else {
    scheduleMinimize();
  }
}

function initHomeAnimations() {
  const homeRoot = document.querySelector("[data-home-page='true']");
  if (!homeRoot) {
    return;
  }

  document.documentElement.classList.add("js-anim-ready");

  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  let backgroundImages = [];
  try {
    backgroundImages = JSON.parse(homeRoot.dataset.backgroundImages || "[]");
  } catch (error) {
    backgroundImages = [];
  }
  if (!reduceMotion && backgroundImages.length > 1) {
    let backgroundIndex = 0;
    window.setInterval(() => {
      backgroundIndex = (backgroundIndex + 1) % backgroundImages.length;
      homeRoot.style.setProperty("--homepage-background-image", `url("${backgroundImages[backgroundIndex]}")`);
    }, 7000);
  }
  const revealItems = [...document.querySelectorAll(".reveal-on-scroll")];

  if (!reduceMotion && "IntersectionObserver" in window) {
    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            const target = entry.target;
            target.classList.add("is-visible");

            const staggerGroups = [...target.querySelectorAll(".home-stagger")];
            staggerGroups.forEach((group) => {
              [...group.children].forEach((child, index) => {
                child.style.transitionDelay = `${Math.min(index * 90, 500)}ms`;
              });
              group.classList.add("is-visible");
            });

            observer.unobserve(target);
          }
        });
      },
      { threshold: 0.18, rootMargin: "0px 0px -60px 0px" }
    );

    revealItems.forEach((item) => observer.observe(item));
  } else {
    revealItems.forEach((item) => {
      item.classList.add("is-visible");
      item.querySelectorAll(".home-stagger").forEach((group) => group.classList.add("is-visible"));
    });
  }

  const heroCard = document.querySelector(".hero-card");
  const winnerPhoto = document.querySelector(".winner-photo");
  if (!heroCard || !winnerPhoto || reduceMotion) {
    return;
  }

  winnerPhoto.classList.add("motion-ready");

  heroCard.addEventListener("mousemove", (event) => {
    const rect = heroCard.getBoundingClientRect();
    const x = ((event.clientX - rect.left) / rect.width - 0.5) * 10;
    const y = ((event.clientY - rect.top) / rect.height - 0.5) * 10;
    winnerPhoto.style.setProperty("--tilt-x", `${x}px`);
    winnerPhoto.style.setProperty("--tilt-y", `${y}px`);
  });

  heroCard.addEventListener("mouseleave", () => {
    winnerPhoto.style.setProperty("--tilt-x", "0px");
    winnerPhoto.style.setProperty("--tilt-y", "0px");
  });
}

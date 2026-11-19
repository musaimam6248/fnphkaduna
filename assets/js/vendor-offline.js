(function () {
  initCollapse();
  initCarousel();
  initLightboxGlobal();
  initChartLite();
})();

function initCollapse() {
  document.addEventListener("click", function (event) {
    var trigger = event.target.closest("[data-bs-toggle='collapse']");
    if (!trigger) {
      return;
    }
    event.preventDefault();
    var targetSelector = trigger.getAttribute("data-bs-target");
    if (!targetSelector) {
      return;
    }
    var target = document.querySelector(targetSelector);
    if (!target) {
      return;
    }
    target.classList.toggle("show");
  });
}

function initCarousel() {
  var carousels = document.querySelectorAll(".carousel");
  carousels.forEach(function (carousel) {
    var items = Array.prototype.slice.call(carousel.querySelectorAll(".carousel-item"));
    if (!items.length) {
      return;
    }

    var activeIndex = items.findIndex(function (item) {
      return item.classList.contains("active");
    });
    if (activeIndex < 0) {
      activeIndex = 0;
      items[0].classList.add("active");
    }

    function show(index) {
      items.forEach(function (item, i) {
        item.classList.toggle("active", i === index);
      });
      activeIndex = index;
    }

    var prev = carousel.querySelector(".carousel-control-prev");
    var next = carousel.querySelector(".carousel-control-next");

    if (prev) {
      prev.addEventListener("click", function () {
        var newIndex = (activeIndex - 1 + items.length) % items.length;
        show(newIndex);
      });
    }

    if (next) {
      next.addEventListener("click", function () {
        var newIndex = (activeIndex + 1) % items.length;
        show(newIndex);
      });
    }

    if (carousel.getAttribute("data-bs-ride") === "carousel" && items.length > 1) {
      setInterval(function () {
        var newIndex = (activeIndex + 1) % items.length;
        show(newIndex);
      }, 5000);
    }
  });
}

function initLightboxGlobal() {
  if (typeof window.GLightbox !== "undefined") {
    return;
  }

  window.GLightbox = function (options) {
    var selector = (options && options.selector) || ".js-glightbox";
    var links = Array.prototype.slice.call(document.querySelectorAll(selector));
    if (!links.length) {
      return { destroy: function () {} };
    }

    var overlay = document.createElement("div");
    overlay.className = "offline-lightbox";
    overlay.innerHTML =
      '<button class="offline-lightbox-close" type="button" aria-label="Close">x</button>' +
      '<div class="offline-lightbox-content">' +
      '<img alt="Preview">' +
      '<p class="offline-lightbox-title"></p>' +
      "</div>";
    document.body.appendChild(overlay);

    var image = overlay.querySelector("img");
    var title = overlay.querySelector(".offline-lightbox-title");
    var closeButton = overlay.querySelector(".offline-lightbox-close");
    var currentIndex = 0;

    function openAt(index) {
      var link = links[index];
      if (!link) {
        return;
      }
      currentIndex = index;
      image.src = link.getAttribute("href") || "";
      title.textContent = link.getAttribute("data-title") || link.getAttribute("title") || "";
      overlay.classList.add("show");
      document.body.style.overflow = "hidden";
    }

    function closeLightbox() {
      overlay.classList.remove("show");
      document.body.style.overflow = "";
    }

    links.forEach(function (link, index) {
      link.addEventListener("click", function (event) {
        event.preventDefault();
        openAt(index);
      });
    });

    closeButton.addEventListener("click", closeLightbox);
    overlay.addEventListener("click", function (event) {
      if (event.target === overlay) {
        closeLightbox();
      }
    });

    document.addEventListener("keydown", function (event) {
      if (!overlay.classList.contains("show")) {
        return;
      }
      if (event.key === "Escape") {
        closeLightbox();
      } else if (event.key === "ArrowRight") {
        openAt((currentIndex + 1) % links.length);
      } else if (event.key === "ArrowLeft") {
        openAt((currentIndex - 1 + links.length) % links.length);
      }
    });

    return {
      destroy: function () {
        if (overlay.parentNode) {
          overlay.parentNode.removeChild(overlay);
        }
      },
    };
  };
}

function initChartLite() {
  if (typeof window.Chart !== "undefined") {
    return;
  }

  function ChartLite(canvasOrCtx, config) {
    this.canvas = canvasOrCtx && canvasOrCtx.canvas ? canvasOrCtx.canvas : canvasOrCtx;
    this.config = config || {};
    this.type = this.config.type || "bar";
    this.data = this.config.data || { labels: [], datasets: [] };
    this.options = this.config.options || {};
    this._boundDraw = this.draw.bind(this);
    if (this.canvas) {
      window.addEventListener("resize", this._boundDraw);
      this.draw();
    }
  }

  ChartLite.prototype.destroy = function () {
    window.removeEventListener("resize", this._boundDraw);
  };

  ChartLite.prototype._context = function () {
    if (!this.canvas) {
      return null;
    }
    var ctx = this.canvas.getContext("2d");
    if (!ctx) {
      return null;
    }

    var width = this.canvas.clientWidth || this.canvas.width || 320;
    var height = this.canvas.clientHeight || this.canvas.height || 220;
    var dpr = window.devicePixelRatio || 1;
    this.canvas.width = Math.max(1, Math.floor(width * dpr));
    this.canvas.height = Math.max(1, Math.floor(height * dpr));
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    ctx.clearRect(0, 0, width, height);
    return { ctx: ctx, width: width, height: height };
  };

  ChartLite.prototype.draw = function () {
    var context = this._context();
    if (!context) {
      return;
    }

    if (this.type === "line") {
      this._drawLine(context);
    } else if (this.type === "doughnut") {
      this._drawDoughnut(context);
    } else {
      this._drawBar(context);
    }
  };

  ChartLite.prototype._dataset = function () {
    return (this.data.datasets && this.data.datasets[0]) || { data: [] };
  };

  ChartLite.prototype._drawBar = function (context) {
    var ctx = context.ctx;
    var width = context.width;
    var height = context.height;
    var dataset = this._dataset();
    var values = (dataset.data || []).map(function (n) {
      return Number(n) || 0;
    });
    if (!values.length) {
      return;
    }

    var max = Math.max.apply(Math, values.concat([1]));
    var gap = 12;
    var chartWidth = width - 26;
    var chartHeight = height - 28;
    var barWidth = Math.max(8, (chartWidth - gap * (values.length - 1)) / values.length);

    values.forEach(function (value, index) {
      var barHeight = (value / max) * chartHeight;
      var x = 13 + index * (barWidth + gap);
      var y = height - 14 - barHeight;
      ctx.fillStyle = Array.isArray(dataset.backgroundColor)
        ? dataset.backgroundColor[index % dataset.backgroundColor.length]
        : dataset.backgroundColor || "#2c7a7b";
      ctx.fillRect(x, y, barWidth, barHeight);
    });
  };

  ChartLite.prototype._drawLine = function (context) {
    var ctx = context.ctx;
    var width = context.width;
    var height = context.height;
    var dataset = this._dataset();
    var values = (dataset.data || []).map(function (n) {
      return Number(n) || 0;
    });
    if (values.length < 2) {
      return;
    }

    var max = Math.max.apply(Math, values.concat([1]));
    var min = Math.min.apply(Math, values);
    var paddingX = 16;
    var paddingY = 16;
    var usableW = width - paddingX * 2;
    var usableH = height - paddingY * 2;

    ctx.lineWidth = 2.5;
    ctx.strokeStyle = dataset.borderColor || "#1d4f57";
    ctx.beginPath();
    values.forEach(function (value, index) {
      var x = paddingX + (index / (values.length - 1)) * usableW;
      var normalized = max === min ? 0.5 : (value - min) / (max - min);
      var y = paddingY + usableH - normalized * usableH;
      if (index === 0) {
        ctx.moveTo(x, y);
      } else {
        ctx.lineTo(x, y);
      }
    });
    ctx.stroke();
  };

  ChartLite.prototype._drawDoughnut = function (context) {
    var ctx = context.ctx;
    var width = context.width;
    var height = context.height;
    var dataset = this._dataset();
    var values = (dataset.data || []).map(function (n) {
      return Math.max(0, Number(n) || 0);
    });
    var total = values.reduce(function (sum, item) {
      return sum + item;
    }, 0);
    if (total <= 0) {
      return;
    }

    var centerX = width / 2;
    var centerY = height / 2;
    var radius = Math.max(18, Math.min(width, height) / 2 - 10);
    var innerRadius = radius * 0.58;
    var angle = -Math.PI / 2;

    values.forEach(function (value, index) {
      var slice = (value / total) * Math.PI * 2;
      var color = Array.isArray(dataset.backgroundColor)
        ? dataset.backgroundColor[index % dataset.backgroundColor.length]
        : dataset.backgroundColor || "#2c7a7b";

      ctx.beginPath();
      ctx.moveTo(centerX, centerY);
      ctx.arc(centerX, centerY, radius, angle, angle + slice);
      ctx.closePath();
      ctx.fillStyle = color;
      ctx.fill();
      angle += slice;
    });

    ctx.beginPath();
    ctx.arc(centerX, centerY, innerRadius, 0, Math.PI * 2);
    ctx.fillStyle = "#ffffff";
    ctx.fill();
  };

  window.Chart = ChartLite;
}

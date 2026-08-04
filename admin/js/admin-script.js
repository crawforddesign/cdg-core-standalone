/**
 * CDG Core Admin — Vanilla JS interactions
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {

    // ── Generic: toggle cdg-disabled on a target element based on a checkbox ──
    function bindToggle(triggerName, targetId) {
      var trigger = document.querySelector('[name="' + triggerName + '"]');
      var target  = document.getElementById(targetId);
      if (!trigger || !target) return;

      function sync() {
        target.classList.toggle("cdg-disabled", !trigger.checked);
      }

      trigger.addEventListener("change", sync);
      sync();
    }

    // Upload type → restrict-to-admins rows
    bindToggle("enable_svg_uploads",    "cdg-svg-admin-row");
    bindToggle("enable_font_uploads",   "cdg-font-admin-row");
    bindToggle("enable_lottie_uploads", "cdg-lottie-admin-row");

    // Feature toggles → sub-settings groups
    bindToggle("enable_documentation", "cdg-doc-sub-settings");
    bindToggle("enable_cpt_widgets",   "cdg-cpt-sub-settings");
    bindToggle("enable_custom_login",  "cdg-login-sub-settings");
    bindToggle("enable_custom_roles",  "cdg-roles-sub-settings");

    // ── Post revisions: disable limit input unless "limited" is selected ──
    var revisionInputs = document.querySelectorAll('[name="post_revisions_mode"]');
    var limitInput     = document.querySelector('[name="post_revisions_limit"]');

    if (revisionInputs.length && limitInput) {
      function syncRevisions() {
        var checked = document.querySelector('[name="post_revisions_mode"]:checked');
        limitInput.disabled = !checked || checked.value !== "limited";
      }
      revisionInputs.forEach(function (r) { r.addEventListener("change", syncRevisions); });
      syncRevisions();
    }

    // ── Theme color mode → custom color row ──
    var colorModes     = document.querySelectorAll('[name="theme_color_mode"]');
    var customColorRow = document.getElementById("cdg-custom-color-row");

    if (colorModes.length && customColorRow) {
      function syncColorMode() {
        var checked = document.querySelector('[name="theme_color_mode"]:checked');
        customColorRow.classList.toggle("cdg-disabled", !checked || checked.value !== "custom");
      }
      colorModes.forEach(function (r) { r.addEventListener("change", syncColorMode); });
      syncColorMode();
    }

    // ── Login logo media picker ──
    var logoUploadBtn = document.getElementById("cdg-login-logo-upload");
    if (logoUploadBtn && window.wp && wp.media) {
      var loginMediaFrame;

      logoUploadBtn.addEventListener("click", function (e) {
        e.preventDefault();

        if (loginMediaFrame) {
          loginMediaFrame.open();
          return;
        }

        loginMediaFrame = wp.media({
          title: "Select Login Logo",
          button: { text: "Use this image" },
          multiple: false,
          library: { type: "image" },
        });

        loginMediaFrame.on("select", function () {
          var attachment = loginMediaFrame
            .state()
            .get("selection")
            .first()
            .toJSON();

          document.getElementById("cdg-login-logo-id").value = attachment.id;

          var img = document.getElementById("cdg-login-logo-img");
          if (img) {
            img.src = attachment.url;
          }

          var preview = document.getElementById("cdg-login-logo-preview");
          if (preview) preview.style.display = "block";

          logoUploadBtn.textContent = "Change Logo";

          var removeBtn = document.getElementById("cdg-login-logo-remove");
          if (removeBtn) removeBtn.style.display = "inline-flex";
        });

        loginMediaFrame.open();
      });

      var logoRemoveBtn = document.getElementById("cdg-login-logo-remove");
      if (logoRemoveBtn) {
        logoRemoveBtn.addEventListener("click", function (e) {
          e.preventDefault();
          document.getElementById("cdg-login-logo-id").value = "";
          var preview = document.getElementById("cdg-login-logo-preview");
          if (preview) preview.style.display = "none";
          logoUploadBtn.textContent = "Select Logo";
          this.style.display = "none";
        });
      }
    }

    // ── Code Snippets repeater ──
    var snippetsList    = document.getElementById("cdg-snippets-list");
    var snippetTemplate = document.getElementById("cdg-snippet-template");
    var snippetAddBtn   = document.getElementById("cdg-snippet-add");
    var snippetsEmpty   = document.getElementById("cdg-snippets-empty");

    if (snippetsList && snippetTemplate && snippetAddBtn) {
      var snippetCounter = parseInt(snippetsList.dataset.count || "0", 10);

      function syncSnippetsEmpty() {
        if (!snippetsEmpty) return;
        snippetsEmpty.style.display = snippetsList.children.length === 0 ? "" : "none";
      }

      function initSnippetRow(row) {
        var typeSelect  = row.querySelector(".cdg-snippet-type");
        var locationRow = row.querySelector(".cdg-snippet-location-row");

        if (typeSelect && locationRow) {
          function syncLocation() {
            var t = typeSelect.value;
            locationRow.style.display = (t === "js" || t === "html") ? "" : "none";
          }
          typeSelect.addEventListener("change", syncLocation);
          syncLocation();
        }

        var removeBtn = row.querySelector(".cdg-snippet-remove");
        if (removeBtn) {
          removeBtn.addEventListener("click", function (e) {
            e.preventDefault();
            if (window.confirm("Remove this snippet?")) {
              row.remove();
              syncSnippetsEmpty();
            }
          });
        }
      }

      snippetsList.querySelectorAll(".cdg-snippet-item").forEach(initSnippetRow);

      snippetAddBtn.addEventListener("click", function (e) {
        e.preventDefault();
        var html = snippetTemplate.innerHTML.replace(/__INDEX__/g, String(snippetCounter));
        snippetCounter++;
        var tmp = document.createElement("div");
        tmp.innerHTML = html;
        var row = tmp.firstElementChild;
        snippetsList.appendChild(row);
        initSnippetRow(row);
        syncSnippetsEmpty();
        var firstInput = row.querySelector(".cdg-input");
        if (firstInput) firstInput.focus();
      });

      syncSnippetsEmpty();
    }

    // ── Color hex input → swatch preview ──
    var colorHexInput = document.querySelector('[name="theme_color_hex"]');
    var colorSwatch   = document.getElementById("cdg-color-swatch");

    if (colorHexInput && colorSwatch) {
      function syncSwatch() {
        var hex = colorHexInput.value.trim();
        if (/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/.test(hex)) {
          colorSwatch.style.backgroundColor = hex;
        }
      }
      colorHexInput.addEventListener("input", syncSwatch);
    }

    // ── Sidebar tab: submenu expand/collapse ──
    document.querySelectorAll(".cdg-si-toggle").forEach(function (btn) {
      btn.addEventListener("click", function () {
        var parent   = btn.dataset.parent;
        var expanded = btn.getAttribute("aria-expanded") === "true";

        btn.setAttribute("aria-expanded", expanded ? "false" : "true");
        btn.closest(".cdg-si-row").classList.toggle("cdg-si-parent-open", !expanded);

        document.querySelectorAll('.cdg-si-child[data-parent="' + parent + '"]').forEach(function (row) {
          row.classList.toggle("cdg-si-open", !expanded);
        });
      });
    });

    // ── Sidebar tab: dashicon picker ──
    var CDG_ICONS = [
      "admin-appearance","admin-comments","admin-generic","admin-home",
      "admin-links","admin-media","admin-multisite","admin-network",
      "admin-page","admin-plugins","admin-post","admin-settings",
      "admin-site","admin-tools","admin-users","analytics",
      "art","awards","building","businessperson",
      "calendar-alt","camera","cart","category",
      "chart-area","chart-bar","chart-line","chart-pie",
      "clipboard","cloud","code-standards","dashboard",
      "database","desktop","editor-code","email",
      "email-alt2","external","filter","flag",
      "format-gallery","format-video","groups","hammer",
      "heart","id","images-alt","info",
      "layout","list-view","location","lock",
      "megaphone","menu","migrate","performance",
      "phone","portfolio","products","randomize",
      "saved","search","share","shield",
      "slides","star-filled","store","superhero",
      "tag","testimonial","text","tickets-alt",
      "update","video-alt3","visibility","warning"
    ];

    var activePickerBtn = null;

    function buildIconPanel() {
      var panel = document.createElement("div");
      panel.className = "cdg-icon-panel";

      var search = document.createElement("input");
      search.type        = "text";
      search.className   = "cdg-icon-search";
      search.placeholder = "Search icons…";
      panel.appendChild(search);

      var grid = document.createElement("div");
      grid.className = "cdg-icon-grid";
      panel.appendChild(grid);

      function renderGrid(filter) {
        grid.innerHTML = "";
        CDG_ICONS.forEach(function (icon) {
          if (filter && icon.indexOf(filter) === -1) return;
          var btn = document.createElement("button");
          btn.type      = "button";
          btn.title     = icon;
          btn.dataset.icon = icon;
          btn.innerHTML = '<span class="dashicons dashicons-' + icon + '"></span>';
          grid.appendChild(btn);
        });
      }

      renderGrid("");

      search.addEventListener("input", function () {
        renderGrid(search.value.trim().toLowerCase());
      });

      grid.addEventListener("click", function (e) {
        var btn = e.target.closest("[data-icon]");
        if (!btn || !activePickerBtn) return;

        var icon      = btn.dataset.icon;
        var container = activePickerBtn.closest(".cdg-custom-link-item");
        if (container) {
          var iconSpan = activePickerBtn.querySelector(".dashicons");
          var hiddenInput = container.querySelector(".cdg-icon-value");
          if (iconSpan) {
            iconSpan.className = "dashicons dashicons-" + icon;
          }
          if (hiddenInput) {
            hiddenInput.value = icon;
          }
        }
        closeIconPanel();
      });

      return panel;
    }

    var iconPanel = null;

    function openIconPanel(triggerBtn) {
      closeIconPanel();
      activePickerBtn = triggerBtn;
      iconPanel = buildIconPanel();
      document.body.appendChild(iconPanel);

      // Highlight current icon.
      var currentIcon = triggerBtn.querySelector(".dashicons");
      if (currentIcon) {
        var curClass = currentIcon.className.replace("dashicons dashicons-", "").trim();
        iconPanel.querySelectorAll("[data-icon]").forEach(function (b) {
          b.classList.toggle("cdg-icon-active", b.dataset.icon === curClass);
        });
      }

      // Position below the button.
      var rect = triggerBtn.getBoundingClientRect();
      iconPanel.style.position = "fixed";
      iconPanel.style.top      = (rect.bottom + window.scrollY + 4) + "px";
      iconPanel.style.left     = rect.left + "px";

      setTimeout(function () {
        iconPanel.querySelector(".cdg-icon-search").focus();
      }, 10);
    }

    function closeIconPanel() {
      if (iconPanel && iconPanel.parentNode) {
        iconPanel.parentNode.removeChild(iconPanel);
      }
      iconPanel = null;
      activePickerBtn = null;
    }

    document.addEventListener("click", function (e) {
      if (iconPanel && !iconPanel.contains(e.target) && e.target !== activePickerBtn && !activePickerBtn.contains(e.target)) {
        closeIconPanel();
      }
    });

    function bindIconPickerBtn(btn) {
      btn.addEventListener("click", function (e) {
        e.stopPropagation();
        if (iconPanel && activePickerBtn === btn) {
          closeIconPanel();
        } else {
          openIconPanel(btn);
        }
      });
    }

    document.querySelectorAll(".cdg-icon-picker-btn").forEach(bindIconPickerBtn);

    // ── Sidebar tab: custom link repeater ──
    var linksList    = document.getElementById("cdg-links-list");
    var linkTemplate = document.getElementById("cdg-link-template");
    var linkAddBtn   = document.getElementById("cdg-link-add");
    var linksEmpty   = document.getElementById("cdg-links-empty");

    if (linksList && linkTemplate && linkAddBtn) {
      var linkCounter = parseInt(linksList.dataset.count || "0", 10);

      function syncLinksEmpty() {
        if (!linksEmpty) return;
        linksEmpty.style.display = linksList.children.length === 0 ? "" : "none";
      }

      function generateLinkId() {
        var hex = "";
        for (var i = 0; i < 8; i++) {
          hex += Math.floor(Math.random() * 16).toString(16);
        }
        return hex;
      }

      function initLinkRow(row) {
        // Bind icon picker.
        var pickerBtn = row.querySelector(".cdg-icon-picker-btn");
        if (pickerBtn) bindIconPickerBtn(pickerBtn);

        // Bind remove button.
        var removeBtn = row.querySelector(".cdg-custom-link-remove");
        if (removeBtn) {
          removeBtn.addEventListener("click", function () {
            if (window.confirm("Remove this link?")) {
              row.remove();
              syncLinksEmpty();
            }
          });
        }
      }

      // Init existing rows (server-rendered).
      linksList.querySelectorAll(".cdg-custom-link-item").forEach(function (row) {
        initLinkRow(row);
      });

      linkAddBtn.addEventListener("click", function (e) {
        e.preventDefault();
        var html = linkTemplate.innerHTML
          .replace(/__INDEX__/g, String(linkCounter));
        linkCounter++;

        var tmp = document.createElement("div");
        tmp.innerHTML = html;
        var row = tmp.firstElementChild;

        // Inject a fresh random id before init.
        var idField = row.querySelector('[name$="[id]"]');
        if (idField) idField.value = generateLinkId();

        linksList.appendChild(row);
        initLinkRow(row);
        syncLinksEmpty();

        var firstInput = row.querySelector(".cdg-input");
        if (firstInput) firstInput.focus();
      });

      syncLinksEmpty();
    }

  });
})();

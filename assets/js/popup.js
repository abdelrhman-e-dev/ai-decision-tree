document.addEventListener("DOMContentLoaded", function () {
  const ADT_STORAGE_KEY = "adt_popup_state";

  function getAdtState() {
    try {
      return JSON.parse(localStorage.getItem(ADT_STORAGE_KEY)) || {};
    } catch (e) {
      return {};
    }
  }

  function setAdtState(postId, updates) {
    const state = getAdtState();
    state[postId] = Object.assign({}, state[postId], updates);
    localStorage.setItem(ADT_STORAGE_KEY, JSON.stringify(state));
  }

  const postState = getAdtState()[adtData.postId];
  if (postState && (postState.shown || postState.closed)) {
    return;
  }

  let isPopupShown = false;
  let currentNode = "start";
  let currentNodeData = null;
  let userJourney = [];
  let leadScore = 0;
  let treeSource = "";

  function showPopup() {
    if (isPopupShown) return;

    document.querySelector(".adt-overlay").classList.add("show");
    document.querySelector(".adt-popup").classList.add("show");
    setAdtState(adtData.postId, { shown: true });

    isPopupShown = true;
    renderNode();
  }

  function fetchNode(nodeId) {
    return fetch(adtData.ajaxUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "adt_get_node",
        nonce: adtData.nonce,
        node: nodeId,
        postId: adtData.postId,
        source: treeSource,
      }),
    }).then((res) => res.json());
  }

  function renderCta(cta) {
    const content = document.getElementById("adt-content");

    const ctaLink   = adtData.ctaLink   || "";
    const ctaTarget = adtData.ctaTarget || "_blank";

    // [SECURITY FIX #3] Build DOM nodes with textContent instead of innerHTML
    // to prevent XSS if the API response or database is ever compromised.
    content.innerHTML = "";

    const h4 = document.createElement("h4");
    h4.textContent = cta.title;

    const p = document.createElement("p");
    p.textContent = cta.text;

    const btn = document.createElement("button");
    btn.className = "adt-contact";
    btn.textContent = cta.button;
    if (ctaLink) {
      btn.addEventListener("click", function () {
        window.open(ctaLink, ctaTarget);
      });
    }

    content.appendChild(h4);
    content.appendChild(p);
    content.appendChild(btn);
  }

  function saveJourney() {
    const content = document.getElementById("adt-content");
    content.innerHTML = `<p>...</p>`;

    const fallback = {
      title:  "شكراً لك",
      text:   "يمكننا مساعدتك في إيجاد الحل المناسب.",
      button: "تحدث مع خبير",
    };

    fetch(adtData.ajaxUrl, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        action: "adt_save_journey",
        nonce: adtData.nonce,
        postId: adtData.postId,
        journey: JSON.stringify(userJourney),
      }),
    })
      .then((res) => res.json())
      .then((data) => {
        const cta = (data && data.data && data.data.cta) || fallback;
        renderCta(cta);
      })
      .catch(() => renderCta(fallback));
  }

  function renderNode() {
    const content = document.getElementById("adt-content");

    if (currentNode === "finish") {
      saveJourney();
      return;
    }

    content.innerHTML = `<p>...</p>`;

    fetchNode(currentNode).then((res) => {
      if (!res.success) {
        content.innerHTML = `<p>حدث خطأ، الرجاء المحاولة لاحقاً.</p>`;
        return;
      }

      currentNodeData = res.data;
      treeSource = res.data.source || treeSource;

      // [SECURITY FIX #3] Build DOM nodes with textContent instead of innerHTML.
      const questionEl = document.createElement("p");
      questionEl.textContent = currentNodeData.question;

      const answersEl = document.createElement("div");
      answersEl.className = "adt-answers";

      currentNodeData.answers.forEach((answer) => {
        const btn = document.createElement("button");
        btn.className = "adt-answer";
        btn.dataset.next = answer.next;
        btn.textContent = answer.text;
        answersEl.appendChild(btn);
      });

      content.innerHTML = "";
      content.appendChild(questionEl);
      content.appendChild(answersEl);
      attachEvents();
    });
  }

  function attachEvents() {
    document.querySelectorAll(".adt-answer").forEach((button, index) => {
      button.addEventListener("click", function () {
        const previousNode   = currentNode;
        const nextNode       = this.dataset.next;
        const selectedAnswer = currentNodeData.answers[index];

        leadScore += selectedAnswer.score;
        userJourney.push({
          node:      previousNode,
          question:  currentNodeData.question,
          answer:    this.innerText.trim(),
          nextNode,
          score:     selectedAnswer.score,
          timestamp: new Date().toISOString(),
        });

        currentNode = nextNode;
        renderNode();
      });
    });
  }

  // ── Scroll trigger ───────────────────────────────────────
  const scrollThreshold = adtData.scrollPercent || 0;
  if (scrollThreshold > 0) {
    window.addEventListener("scroll", function () {
      if (isPopupShown) return;
      const scrollable = document.documentElement.scrollHeight - document.documentElement.clientHeight;
      const pct = scrollable <= 0 ? 100 : (window.scrollY / scrollable) * 100;
      if (pct >= scrollThreshold) showPopup();
    });
  }

  // ── Timer trigger ────────────────────────────────────────
  const timerDelay = adtData.timerDelay || 0;
  if (timerDelay > 0) {
    setTimeout(showPopup, timerDelay * 1000);
  }

  // ── Exit-intent trigger ──────────────────────────────────
  if (adtData.triggerExit) {
    document.addEventListener("mouseleave", function (e) {
      if (e.clientY < 20) showPopup();
    });
  }

  // ── Close handlers ───────────────────────────────────────
  const closeBtn = document.querySelector(".adt-close");
  if (closeBtn) {
    closeBtn.addEventListener("click", function () {
      setAdtState(adtData.postId, { closed: true });
      document.querySelector(".adt-popup").classList.remove("show");
      document.querySelector(".adt-overlay").classList.remove("show");
    });
  }

  const overlay = document.querySelector(".adt-overlay");
  if (overlay) {
    overlay.addEventListener("click", function () {
      setAdtState(adtData.postId, { closed: true });
      document.querySelector(".adt-popup").classList.remove("show");
      overlay.classList.remove("show");
    });
  }
});
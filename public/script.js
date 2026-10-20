(function () {
  let products = [];
  const state = {
    query: "",
    series: "all",
    status: "all",
    sort: "featured",
    visibleCount: Number.MAX_SAFE_INTEGER,
    favorites: new Set(JSON.parse(localStorage.getItem("oleena:favorites") || "[]")),
    cart: new Map(JSON.parse(localStorage.getItem("oleena:cart") || "[]")),
    shipping: { area: null, areas: [], rates: [] },
    order: null,
    submitting: false,
    idempotencyKey: null
  };

  const seriesFilters = document.querySelector("#seriesFilters");
  const productGrid = document.querySelector("#productGrid");
  const searchInput = document.querySelector("#searchInput");
  const statusFilter = document.querySelector("#statusFilter");
  const sortFilter = document.querySelector("#sortFilter");
  const resetFilters = document.querySelector("#resetFilters");
  const resultTitle = document.querySelector("#resultTitle");
  const resultCount = document.querySelector("#resultCount");
  const loadMoreWrap = document.querySelector("#loadMoreWrap");
  const loadMoreProducts = document.querySelector("#loadMoreProducts");
  const emptyState = document.querySelector("#emptyState");
  const totalProducts = document.querySelector("#totalProducts");
  const totalSeries = document.querySelector("#totalSeries");
  const dialog = document.querySelector("#productDialog");
  const dialogContent = document.querySelector("#dialogContent");
  const closeDialog = document.querySelector("#closeDialog");
  const imageViewer = document.querySelector("#imageViewer");
  const imageViewerImg = document.querySelector("#imageViewerImg");
  const closeImageViewer = document.querySelector("#closeImageViewer");
  const openCart = document.querySelector("#openCart");
  const cartCount = document.querySelector("#cartCount");
  const cartDialog = document.querySelector("#cartDialog");
  const closeCart = document.querySelector("#closeCart");
  const cartItems = document.querySelector("#cartItems");
  const cartEmpty = document.querySelector("#cartEmpty");
  const cartSubtotal = document.querySelector("#cartSubtotal");
  const cartShipping = document.querySelector("#cartShipping");
  const cartTotal = document.querySelector("#cartTotal");
  const checkoutCart = document.querySelector("#checkoutCart");
  const cartFooter = document.querySelector("#cartFooter");
  const orderPanel = document.querySelector("#orderPanel");
  const clearCart = document.querySelector("#clearCart");
  const checkoutName = document.querySelector("#checkoutName");
  const checkoutPhone = document.querySelector("#checkoutPhone");
  const checkoutAddress = document.querySelector("#checkoutAddress");
  const checkoutArea = document.querySelector("#checkoutArea");
  const areaResults = document.querySelector("#areaResults");
  const shippingService = document.querySelector("#shippingService");
  const shippingStatus = document.querySelector("#shippingStatus");
  const checkoutNote = document.querySelector("#checkoutNote");
  const mobileCartBar = document.querySelector("#mobileCartBar");
  const mobileCartSummary = document.querySelector("#mobileCartSummary");
  const pageLoaderOverlay = document.querySelector("#pageLoaderOverlay");
  const toast = document.querySelector("#toast");

  const whatsappNumber = "6281236773427";
  let linkLoading = false;
  const formatter = new Intl.NumberFormat("id-ID", {
    style: "currency",
    currency: "IDR",
    maximumFractionDigits: 0
  });

  const toneMap = {
    black: "#161313",
    beige: "#d4bea4",
    chocolate: "#6d4635",
    cloud: "#dce8ee",
    cosmos: "#c7b8c8",
    "dark blue": "#273d70",
    dusty: "#c99092",
    grey: "#a9aaa7",
    iris: "#8c809d",
    ivory: "#f1eadc",
    latte: "#b98961",
    lavender: "#cfc2df",
    "light green": "#c7d7bb",
    maroon: "#5d1728",
    mauve: "#b48690",
    mist: "#cad4d2",
    mocha: "#916f5c",
    mocca: "#9c735f",
    navy: "#182947",
    "navy blue": "#1d3559",
    nude: "#d9b9a6",
    oat: "#d9c7ad",
    oatmilk: "#e8decf",
    pink: "#e6b6c1",
    rose: "#dfb9bd",
    sage: "#9aaa80",
    sand: "#d4c1a3",
    taupe: "#9e8c7b",
    white: "#f3f1eb"
  };

  function formatPrice(value) {
    return formatter.format(value).replace(/\s/g, "");
  }

  function shortPrice(value) {
    return `${Math.round(value / 1000)}k`;
  }

  function bySeries() {
    return products.reduce((map, product) => {
      map.set(product.series, (map.get(product.series) || 0) + 1);
      return map;
    }, new Map());
  }

  function getTone(product) {
    const key = product.name.toLowerCase();
    if (toneMap[key]) return toneMap[key];
    let hash = 0;
    for (const char of product.name) hash = char.charCodeAt(0) + ((hash << 5) - hash);
    const hue = Math.abs(hash) % 360;
    return `hsl(${hue} 28% 68%)`;
  }

  function normalize(value) {
    return value.toLowerCase().trim();
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#39;");
  }

  // Data produk berasal dari database, jadi setiap teks di-escape sebelum masuk innerHTML.
  function escapeProduct(product) {
    return {
      ...product,
      id: escapeHtml(product.id),
      name: escapeHtml(product.name),
      series: escapeHtml(product.series),
      image: escapeHtml(product.image),
      imageSmall: escapeHtml(product.imageSmall || product.image)
    };
  }

  // Foto hasil upload punya versi kartu (720px) dan HD (1440px); browser memilih sesuai kepadatan layar HP.
  function productImageTag(product, className = "") {
    const srcset = product.imageSmall !== product.image
      ? ` srcset="${product.imageSmall} 720w, ${product.image} 1440w" sizes="(max-width: 739px) 50vw, 25vw"`
      : "";
    const cls = className ? ` class="${className}"` : "";
    return `<img${cls} src="${product.imageSmall}"${srcset} width="720" height="960" alt="${product.name} - ${product.series}" loading="lazy" decoding="async">`;
  }

  async function loadProducts() {
    const response = await fetch("api/products.php", { headers: { Accept: "application/json" } });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();
    return Array.isArray(data.products) ? data.products : [];
  }

  function getFilteredProducts() {
    const query = normalize(state.query);
    let list = products.filter((product) => {
      const text = normalize(`${product.name} ${product.series}`);
      const matchesQuery = !query || text.includes(query);
      const matchesSeries = state.series === "all" || product.series === state.series;
      const matchesStatus = state.status === "all" || product.status === state.status;
      return matchesQuery && matchesSeries && matchesStatus;
    });

    list = list.slice().sort((a, b) => {
      if (state.sort === "price-asc") return a.price - b.price || a.name.localeCompare(b.name);
      if (state.sort === "price-desc") return b.price - a.price || a.name.localeCompare(b.name);
      if (state.sort === "name") return a.name.localeCompare(b.name);
      return a.page - b.page || products.indexOf(a) - products.indexOf(b);
    });

    return list;
  }

  function renderSeriesFilters() {
    const counts = bySeries();
    const entries = [["all", "Semua", products.length], ...Array.from(counts, ([name, count]) => [name, name, count])];
    seriesFilters.innerHTML = entries.map(([value, label, count]) => `
      <button class="segment-button" type="button" data-series="${escapeHtml(value)}" aria-pressed="${state.series === value}">
        <span>${escapeHtml(label)}</span>
        <span class="segment-count">${count}</span>
      </button>
    `).join("");
  }

  function renderProducts() {
    const list = getFilteredProducts();
    const currentSeries = state.series === "all" ? "Semua Produk" : state.series;
    resultTitle.textContent = currentSeries;
    resultCount.textContent = `${list.length} item`;
    emptyState.hidden = list.length > 0;
    loadMoreWrap.hidden = true;

    productGrid.innerHTML = list.map((raw) => {
      const isFavorite = state.favorites.has(raw.id);
      const isSoldOut = raw.status === "sold-out";
      const product = escapeProduct(raw);
      return `
        <article class="product-card ${isSoldOut ? "is-sold-out" : ""}">
          <div class="product-media" data-product="${product.id}" title="Lihat detail ${product.name}">
            ${productImageTag(product)}
            <span class="price-badge">${shortPrice(product.price)}</span>
            <span class="status-badge">${isSoldOut ? "Sold out" : "Ready"}</span>
          </div>
          <div class="product-body">
            <div class="product-title-row">
              <h3>${product.name}</h3>
              <button class="favorite-button" type="button" data-favorite="${product.id}" aria-label="Favorit ${product.name}" aria-pressed="${isFavorite}" title="${isFavorite ? "Tersimpan" : "Simpan"}"></button>
            </div>
            <p class="series-name">${product.series}</p>
            <div class="card-actions">
              <span class="tone-chip" style="--tone: ${getTone(raw)}">${product.name}</span>
              <button class="details-button" type="button" data-product="${product.id}">View</button>
            </div>
            <button class="cart-add-button" type="button" data-add-cart="${product.id}" ${isSoldOut ? "disabled" : ""}>
              ${cartButtonLabel(raw)}
            </button>
          </div>
        </article>
      `;
    }).join("");
  }

  function renderStats() {
    totalProducts.textContent = products.length;
    totalSeries.textContent = bySeries().size;
  }

  function render() {
    renderSeriesFilters();
    renderProducts();
    renderCart();
  }

  function resetVisibleCount() {
    state.visibleCount = Number.MAX_SAFE_INTEGER;
  }

  function orderText(product) {
    return [
      "Halo Oleena, saya ingin order:",
      `${product.name} - ${product.series}`,
      `Harga: ${formatPrice(product.price)}`,
      `Status katalog: ${product.status === "sold-out" ? "Sold out" : "Ready"}`
    ].join("\n");
  }

  function whatsappUrl(product) {
    const message = product ? orderText(product) : "Halo Oleena, saya ingin tanya katalog 2026.";
    return `https://wa.me/${whatsappNumber}?text=${encodeURIComponent(message)}`;
  }

  function saveCart() {
    localStorage.setItem("oleena:cart", JSON.stringify(Array.from(state.cart.entries())));
    scheduleRatesRefresh();
  }

  function getCartProducts() {
    return Array.from(state.cart.entries()).map(([id, qty]) => {
      const product = products.find((item) => item.id === id);
      return product ? { ...product, qty } : null;
    }).filter(Boolean);
  }

  function getCartItemCount() {
    return getCartProducts().reduce((total, product) => total + product.qty, 0);
  }

  function getCartTotal() {
    return getCartProducts().reduce((total, product) => total + product.price * product.qty, 0);
  }

  function getSelectedRate() {
    return state.shipping.rates.find((rate) => rate.key === shippingService.value) || null;
  }

  function parseShippingValue() {
    const rate = getSelectedRate();
    return rate ? rate.price : 0;
  }

  function setShippingStatus(message, isError = false) {
    shippingStatus.textContent = message;
    shippingStatus.classList.toggle("is-error", isError);
  }

  function rateLabel(rate) {
    const duration = rate.duration ? `, ${rate.duration}` : "";
    return `${rate.courierName} ${rate.serviceName} - ${formatPrice(rate.price)}${duration}`;
  }

  function renderRates() {
    const previous = shippingService.value;
    const rates = state.shipping.rates;
    if (!rates.length) {
      shippingService.innerHTML = `<option value="">${state.shipping.area ? "Tidak ada kurir tersedia" : "Pilih tujuan dulu"}</option>`;
      shippingService.disabled = true;
      return;
    }
    shippingService.innerHTML = rates.map((rate) => `
      <option value="${escapeHtml(rate.key)}">${escapeHtml(rateLabel(rate))}</option>
    `).join("");
    shippingService.disabled = false;
    // Pertahankan pilihan kurir sebelumnya bila masih ada, selain itu pakai yang termurah.
    shippingService.value = rates.some((rate) => rate.key === previous) ? previous : rates[0].key;
  }

  async function postJson(url, body, signal) {
    const response = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(body),
      signal
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
    return data;
  }

  let ratesController = null;
  let ratesTimer = null;

  function scheduleRatesRefresh() {
    window.clearTimeout(ratesTimer);
    ratesTimer = window.setTimeout(refreshRates, 500);
  }

  async function refreshRates() {
    if (ratesController) ratesController.abort();
    const area = state.shipping.area;
    const items = Array.from(state.cart.entries()).map(([id, qty]) => ({ id, qty }));
    if (!area || !items.length) {
      state.shipping.rates = [];
      renderRates();
      setShippingStatus(area ? "Keranjang masih kosong" : "");
      renderCart();
      return;
    }

    ratesController = new AbortController();
    shippingService.disabled = true;
    setShippingStatus("Menghitung ongkir...");
    try {
      const data = await postJson("api/shipping-rates.php", { areaId: area.id, items }, ratesController.signal);
      state.shipping.rates = data.rates || [];
      setShippingStatus(state.shipping.rates.length ? `Berat paket ${(data.weightGram / 1000).toLocaleString("id-ID")} kg` : "Tidak ada kurir untuk tujuan ini");
    } catch (error) {
      if (error.name === "AbortError") return;
      state.shipping.rates = [];
      setShippingStatus(error.message, true);
    }
    renderRates();
    renderCart();
  }

  let areaController = null;
  let areaTimer = null;

  function closeAreaResults() {
    areaResults.hidden = true;
    checkoutArea.setAttribute("aria-expanded", "false");
  }

  function renderAreaResults(content) {
    areaResults.innerHTML = content;
    areaResults.hidden = false;
    checkoutArea.setAttribute("aria-expanded", "true");
  }

  async function searchAreas(query) {
    if (areaController) areaController.abort();
    areaController = new AbortController();
    renderAreaResults("<p>Mencari wilayah...</p>");
    try {
      const response = await fetch(`api/shipping-areas.php?q=${encodeURIComponent(query)}`, {
        headers: { Accept: "application/json" },
        signal: areaController.signal
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
      state.shipping.areas = data.areas || [];
      renderAreaResults(state.shipping.areas.length
        ? state.shipping.areas.map((area, index) => `
          <button type="button" role="option" data-area-index="${index}">${escapeHtml(area.name)}</button>
        `).join("")
        : "<p>Wilayah tidak ditemukan</p>");
    } catch (error) {
      if (error.name === "AbortError") return;
      renderAreaResults(`<p>${escapeHtml(error.message)}</p>`);
    }
  }

  function selectArea(area) {
    state.shipping.area = area;
    checkoutArea.value = area.name;
    closeAreaResults();
    refreshRates();
  }

  function cartButtonLabel(product) {
    if (product.status === "sold-out") return "Sold out";
    const qty = state.cart.get(product.id) || 0;
    return qty > 0 ? `Add more (${qty})` : "Add to cart";
  }

  function syncProductCartButton(productId) {
    const product = products.find((item) => item.id === productId);
    if (!product) return;
    document.querySelectorAll(".cart-add-button[data-add-cart]").forEach((button) => {
      if (button.dataset.addCart !== productId) return;
      button.textContent = cartButtonLabel(product);
      button.disabled = product.status === "sold-out";
    });
  }

  function syncAllProductCartButtons() {
    document.querySelectorAll(".cart-add-button[data-add-cart]").forEach((button) => {
      const product = products.find((item) => item.id === button.dataset.addCart);
      if (!product) return;
      button.textContent = cartButtonLabel(product);
      button.disabled = product.status === "sold-out";
    });
  }

  const orderStatusLabels = {
    pending_payment: "Menunggu pembayaran",
    paid: "Sudah dibayar",
    processing: "Sedang dikemas",
    shipped: "Sudah dikirim",
    completed: "Selesai",
    cancelled: "Dibatalkan",
    expired: "Kedaluwarsa"
  };

  function orderWhatsappUrl(order) {
    const message = [
      `Halo Oleena, saya sudah membuat pesanan ${order.code}.`,
      ...order.items.map((item, index) => `${index + 1}. ${item.name} - ${item.series} x${item.qty} (${formatPrice(item.lineTotal)})`),
      `Kurir: ${order.courier}`,
      `Total: ${formatPrice(order.total)}`
    ].join("\n");
    return `https://wa.me/${whatsappNumber}?text=${encodeURIComponent(message)}`;
  }

  function getOrderToken(code) {
    try {
      return JSON.parse(localStorage.getItem("oleena:orders") || "{}")[code] || null;
    } catch (error) {
      return null;
    }
  }

  function saveOrderToken(code, token) {
    try {
      const saved = JSON.parse(localStorage.getItem("oleena:orders") || "{}");
      saved[code] = token;
      const recent = Object.entries(saved).slice(-10);
      localStorage.setItem("oleena:orders", JSON.stringify(Object.fromEntries(recent)));
    } catch (error) {
      console.warn(error);
    }
  }

  function renderOrderPanel() {
    const order = state.order;
    orderPanel.hidden = !order;
    cartFooter.hidden = Boolean(order);
    if (!order) return;
    cartItems.hidden = true;
    cartEmpty.hidden = true;

    const items = order.items.map((item) => `
      <li><span>${escapeHtml(item.name)} - ${escapeHtml(item.series)} x${item.qty}</span><strong>${formatPrice(item.lineTotal)}</strong></li>
    `).join("");
    orderPanel.innerHTML = `
      <p class="eyebrow">Pesanan dibuat</p>
      <h3>${escapeHtml(order.code)}</h3>
      <p class="order-status">${escapeHtml(orderStatusLabels[order.status] || order.status)}</p>
      <ul class="order-lines">${items}</ul>
      <dl class="order-summary">
        <div><dt>Tujuan</dt><dd>${escapeHtml(order.areaName)}</dd></div>
        <div><dt>Kurir</dt><dd>${escapeHtml(order.courier)}</dd></div>
        <div><dt>Subtotal</dt><dd>${formatPrice(order.subtotal)}</dd></div>
        <div><dt>Ongkir</dt><dd>${formatPrice(order.shippingCost)}</dd></div>
        <div class="is-total"><dt>Total bayar</dt><dd>${formatPrice(order.total)}</dd></div>
      </dl>
      <div class="order-actions">
        ${order.status === "pending_payment" ? `
          <button class="primary-button" type="button" data-order-pay>Bayar sekarang</button>
          <button class="secondary-button" type="button" data-order-refresh>Cek status pembayaran</button>
        ` : ""}
        <a class="secondary-button" href="${orderWhatsappUrl(order)}" target="_blank" rel="noreferrer">Hubungi via WhatsApp</a>
        <button class="secondary-button" type="button" data-order-close>Belanja lagi</button>
      </div>
    `;
  }

  async function fetchOrderStatus(code, token) {
    const response = await fetch(`api/order-status.php?code=${encodeURIComponent(code)}&token=${encodeURIComponent(token)}`, {
      headers: { Accept: "application/json" }
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || `HTTP ${response.status}`);
    return data.order;
  }

  async function refreshOrder() {
    const token = state.order && getOrderToken(state.order.code);
    if (!token) return;
    try {
      state.order = await fetchOrderStatus(state.order.code, token);
      renderOrderPanel();
    } catch (error) {
      showToast(error.message);
    }
  }

  async function payOrder(button) {
    const token = getOrderToken(state.order.code);
    if (!token) {
      showToast("Token pesanan tidak ditemukan di perangkat ini, hubungi kami via WhatsApp");
      return;
    }
    button.disabled = true;
    button.textContent = "Membuka pembayaran...";
    try {
      const data = await postJson("api/payment.php", { code: state.order.code, token });
      window.location.href = data.redirectUrl;
    } catch (error) {
      showToast(error.message);
      button.disabled = false;
      button.textContent = "Bayar sekarang";
    }
  }

  // Setelah kembali dari halaman Midtrans (?order=KODE), tampilkan status pesanan.
  // Webhook bisa datang sedikit terlambat, jadi status dicek ulang beberapa kali.
  async function resumeOrderFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const code = params.get("order");
    if (!code) return;
    window.history.replaceState(null, "", window.location.pathname);
    const token = getOrderToken(code);
    if (!token) return;
    try {
      state.order = await fetchOrderStatus(code, token);
    } catch (error) {
      showToast(error.message);
      return;
    }
    openCartDialog();
    for (let attempt = 0; attempt < 5 && state.order && state.order.status === "pending_payment"; attempt++) {
      await new Promise((resolve) => window.setTimeout(resolve, 3000));
      await refreshOrder();
    }
  }

  async function submitOrder() {
    if (state.submitting) return;
    const rate = getSelectedRate();
    state.submitting = true;
    state.idempotencyKey = state.idempotencyKey || crypto.randomUUID();
    checkoutCart.textContent = "Memproses...";
    checkoutCart.setAttribute("aria-disabled", "true");
    try {
      const data = await postJson("api/orders.php", {
        idempotencyKey: state.idempotencyKey,
        customer: {
          name: checkoutName.value,
          phone: checkoutPhone.value,
          address: checkoutAddress.value,
          note: checkoutNote.value
        },
        shipping: { areaId: state.shipping.area.id, areaName: state.shipping.area.name, rateKey: rate.key },
        items: Array.from(state.cart.entries()).map(([id, qty]) => ({ id, qty }))
      });
      saveOrderToken(data.order.code, data.accessToken);
      state.order = data.order;
      state.idempotencyKey = null;
      state.cart.clear();
      localStorage.setItem("oleena:cart", "[]");
      checkoutNote.value = "";
      syncAllProductCartButtons();
    } catch (error) {
      showToast(error.message);
      if (/kurir/i.test(error.message)) refreshRates();
    } finally {
      state.submitting = false;
      checkoutCart.textContent = "Buat pesanan";
      renderCart();
    }
  }

  const reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)");

  function restartAnimation(element, className) {
    if (!element) return;
    element.classList.remove(className);
    void element.offsetWidth; // paksa reflow agar animasi bisa diulang
    element.classList.add(className);
  }

  // Tombol berubah sejenak menjadi "Ditambahkan", lalu kembali ke label normal.
  function confirmButton(button, product) {
    if (!button) return;
    window.clearTimeout(button.confirmTimer);
    button.classList.add("is-added");
    button.textContent = "✓ Ditambahkan";
    button.confirmTimer = window.setTimeout(() => {
      button.classList.remove("is-added");
      button.textContent = button.classList.contains("cart-add-button") ? cartButtonLabel(product) : "Tambah keranjang";
    }, 1100);
  }

  // Foto produk "terbang" ke ikon keranjang di header.
  function flyToCart(sourceImage) {
    if (!sourceImage || reduceMotion.matches || typeof sourceImage.animate !== "function") return;
    const from = sourceImage.getBoundingClientRect();
    const to = cartCount.getBoundingClientRect();
    if (!from.width || !to.width) return;

    const flyer = sourceImage.cloneNode();
    flyer.removeAttribute("srcset");
    flyer.src = sourceImage.currentSrc || sourceImage.src;
    flyer.className = "cart-flyer";
    Object.assign(flyer.style, { left: `${from.left}px`, top: `${from.top}px`, width: `${from.width}px`, height: `${from.height}px` });
    document.body.append(flyer);

    const dx = to.left + to.width / 2 - (from.left + from.width / 2);
    const dy = to.top + to.height / 2 - (from.top + from.height / 2);
    flyer.animate([
      { transform: "translate(0, 0) scale(1)", opacity: 0.95, borderRadius: "6px" },
      { transform: `translate(${dx * 0.45}px, ${dy * 0.45 - 40}px) scale(0.45)`, opacity: 0.9, borderRadius: "16px", offset: 0.45 },
      { transform: `translate(${dx}px, ${dy}px) scale(0.06)`, opacity: 0.2, borderRadius: "50%" }
    ], { duration: 650, easing: "cubic-bezier(0.4, 0, 0.2, 1)" }).finished
      .catch(() => {})
      .finally(() => {
        flyer.remove();
        restartAnimation(cartCount, "is-bumped");
      });
  }

  function addToCart(productId, button = null) {
    state.order = null;
    const product = products.find((item) => item.id === productId);
    if (!product || product.status === "sold-out") {
      showToast("Produk ini sedang sold out");
      return;
    }
    state.cart.set(productId, (state.cart.get(productId) || 0) + 1);
    saveCart();
    syncProductCartButton(productId);
    renderCart();
    showToast(`${product.name} masuk keranjang`);

    if (navigator.vibrate) navigator.vibrate(12);
    confirmButton(button, product);
    restartAnimation(mobileCartBar, "is-bumped");
    // Dari dalam dialog detail, foto tidak diterbangkan karena dialog berada di lapisan paling atas.
    const card = button && !button.closest("dialog") ? button.closest(".product-card") : null;
    if (card) flyToCart(card.querySelector(".product-media img"));
    else restartAnimation(cartCount, "is-bumped");
  }

  function updateCartQty(productId, delta) {
    const current = state.cart.get(productId) || 0;
    const next = current + delta;
    if (next <= 0) state.cart.delete(productId);
    else state.cart.set(productId, next);
    saveCart();
    syncProductCartButton(productId);
    renderCart();
  }

  function removeFromCart(productId) {
    state.cart.delete(productId);
    saveCart();
    syncProductCartButton(productId);
    renderCart();
  }

  function clearCartItems() {
    state.cart.clear();
    saveCart();
    syncAllProductCartButtons();
    renderCart();
    showToast("Keranjang dikosongkan");
  }

  function renderCart() {
    const items = getCartProducts();
    const itemCount = getCartItemCount();
    const subtotal = getCartTotal();
    const shipping = parseShippingValue();
    const grandTotal = subtotal + shipping;
    const hasCustomerData = checkoutName.value.trim()
      && checkoutPhone.value.trim()
      && checkoutAddress.value.trim()
      && state.shipping.area
      && getSelectedRate();

    cartCount.textContent = itemCount;
    mobileCartSummary.textContent = `${itemCount} item - ${formatPrice(grandTotal)}`;
    mobileCartBar.hidden = itemCount === 0;
    openCart.setAttribute("aria-label", `Buka keranjang, ${itemCount} item`);
    cartEmpty.hidden = items.length > 0;
    cartItems.hidden = items.length === 0;
    clearCart.disabled = items.length === 0;
    cartSubtotal.textContent = formatPrice(subtotal);
    cartShipping.textContent = formatPrice(shipping);
    cartTotal.textContent = formatPrice(grandTotal);
    checkoutCart.setAttribute("aria-disabled", items.length === 0 || !hasCustomerData || state.submitting ? "true" : "false");

    cartItems.innerHTML = items.map(escapeProduct).map((product) => `
      <article class="cart-item">
        <img src="${product.image}" alt="${product.name} - ${product.series}">
        <div class="cart-item-body">
          <h3>${product.name}</h3>
          <p>${product.series}</p>
          <strong>${formatPrice(product.price)}</strong>
        </div>
        <div class="quantity-control" aria-label="Jumlah ${product.name}">
          <button type="button" data-cart-dec="${product.id}" aria-label="Kurangi ${product.name}">-</button>
          <span>${product.qty}</span>
          <button type="button" data-cart-inc="${product.id}" aria-label="Tambah ${product.name}">+</button>
        </div>
        <button class="remove-cart-button" type="button" data-cart-remove="${product.id}">Hapus</button>
      </article>
    `).join("");
    renderOrderPanel();
  }

  async function copyOrder(product) {
    const text = orderText(product);
    try {
      await navigator.clipboard.writeText(text);
    } catch (error) {
      const area = document.createElement("textarea");
      area.value = text;
      area.setAttribute("readonly", "");
      area.style.position = "fixed";
      area.style.left = "-9999px";
      document.body.append(area);
      area.select();
      document.execCommand("copy");
      area.remove();
    }
    showToast("Detail produk disalin");
  }

  function showToast(message) {
    toast.textContent = message;
    toast.classList.add("is-visible");
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => toast.classList.remove("is-visible"), 1800);
  }

  function openProduct(raw) {
    const product = escapeProduct(raw);
    dialogContent.innerHTML = `
      <button class="dialog-image-button" type="button" data-zoom="${product.image}" aria-label="Perbesar foto ${product.name}">
        <img class="dialog-image" src="${product.image}" width="1440" height="1920" alt="${product.name} - ${product.series}" decoding="async">
        <span class="zoom-hint">Ketuk untuk perbesar</span>
      </button>
      <section class="dialog-details">
        <p class="eyebrow">${product.series}</p>
        <h2>${product.name}</h2>
        <div class="dialog-meta">
          <span class="pill">${formatPrice(product.price)}</span>
          <span class="pill">${product.status === "sold-out" ? "Sold out" : "Ready stock"}</span>
        </div>
        <div class="dialog-actions">
          <button class="primary-button" type="button" data-add-cart="${product.id}" ${product.status === "sold-out" ? "disabled" : ""}>${product.status === "sold-out" ? "Sold out" : "Tambah keranjang"}</button>
          <a class="secondary-button" href="${whatsappUrl(raw)}" target="_blank" rel="noreferrer">Tanya via WhatsApp</a>
          <button class="secondary-button" type="button" data-copy="${product.id}">Salin detail</button>
        </div>
      </section>
    `;
    if (typeof dialog.showModal === "function") dialog.showModal();
    else dialog.setAttribute("open", "");
  }

  function closeProduct() {
    if (dialog.open && typeof dialog.close === "function") dialog.close();
    else dialog.removeAttribute("open");
  }

  searchInput.addEventListener("input", (event) => {
    state.query = event.target.value;
    resetVisibleCount();
    renderProducts();
  });

  statusFilter.addEventListener("change", (event) => {
    state.status = event.target.value;
    resetVisibleCount();
    renderProducts();
  });

  sortFilter.addEventListener("change", (event) => {
    state.sort = event.target.value;
    resetVisibleCount();
    renderProducts();
  });

  resetFilters.addEventListener("click", () => {
    state.query = "";
    state.series = "all";
    state.status = "all";
    state.sort = "featured";
    resetVisibleCount();
    searchInput.value = "";
    statusFilter.value = "all";
    sortFilter.value = "featured";
    render();
  });

  seriesFilters.addEventListener("click", (event) => {
    const button = event.target.closest("[data-series]");
    if (!button) return;
    state.series = button.dataset.series;
    resetVisibleCount();
    render();
  });

  loadMoreProducts.addEventListener("click", () => {
    state.visibleCount = getFilteredProducts().length;
    renderProducts();
  });

  productGrid.addEventListener("click", (event) => {
    const favorite = event.target.closest("[data-favorite]");
    if (favorite) {
      const id = favorite.dataset.favorite;
      if (state.favorites.has(id)) state.favorites.delete(id);
      else state.favorites.add(id);
      localStorage.setItem("oleena:favorites", JSON.stringify(Array.from(state.favorites)));
      renderProducts();
      return;
    }

    const detail = event.target.closest("[data-product]");
    const addCart = event.target.closest("[data-add-cart]");
    if (addCart) {
      addToCart(addCart.dataset.addCart, addCart);
      return;
    }
    if (!detail) return;
    const product = products.find((item) => item.id === detail.dataset.product);
    if (product) openProduct(product);
  });

  function openImageViewer(src, alt) {
    imageViewerImg.src = src;
    imageViewerImg.alt = alt;
    if (typeof imageViewer.showModal === "function") imageViewer.showModal();
    else imageViewer.setAttribute("open", "");
  }

  function closeImage() {
    if (imageViewer.open && typeof imageViewer.close === "function") imageViewer.close();
    else imageViewer.removeAttribute("open");
  }

  closeImageViewer.addEventListener("click", closeImage);
  imageViewer.addEventListener("click", (event) => {
    if (event.target === imageViewer) closeImage();
  });

  dialog.addEventListener("click", (event) => {
    const zoom = event.target.closest("[data-zoom]");
    if (zoom) {
      openImageViewer(zoom.dataset.zoom, zoom.querySelector("img").alt);
      return;
    }

    const addCart = event.target.closest("[data-add-cart]");
    if (addCart) {
      addToCart(addCart.dataset.addCart, addCart);
      return;
    }

    const copy = event.target.closest("[data-copy]");
    if (copy) {
      const product = products.find((item) => item.id === copy.dataset.copy);
      if (product) copyOrder(product);
      return;
    }
    if (event.target === dialog) closeProduct();
  });

  closeDialog.addEventListener("click", closeProduct);

  function openCartDialog() {
    renderCart();
    if (typeof cartDialog.showModal === "function") cartDialog.showModal();
    else cartDialog.setAttribute("open", "");
  }

  openCart.addEventListener("click", openCartDialog);
  mobileCartBar.addEventListener("click", openCartDialog);

  closeCart.addEventListener("click", () => {
    if (cartDialog.open && typeof cartDialog.close === "function") cartDialog.close();
    else cartDialog.removeAttribute("open");
  });

  cartDialog.addEventListener("click", (event) => {
    if (event.target === cartDialog) {
      cartDialog.close();
      return;
    }

    const inc = event.target.closest("[data-cart-inc]");
    if (inc) {
      updateCartQty(inc.dataset.cartInc, 1);
      return;
    }

    const dec = event.target.closest("[data-cart-dec]");
    if (dec) {
      updateCartQty(dec.dataset.cartDec, -1);
      return;
    }

    const remove = event.target.closest("[data-cart-remove]");
    if (remove) {
      removeFromCart(remove.dataset.cartRemove);
    }
  });

  clearCart.addEventListener("click", clearCartItems);

  [checkoutName, checkoutPhone, checkoutAddress, checkoutNote].forEach((field) => {
    field.addEventListener("input", () => {
      renderCart();
    });
  });

  shippingService.addEventListener("change", renderCart);

  checkoutArea.addEventListener("input", () => {
    const query = checkoutArea.value.trim();
    if (state.shipping.area) {
      state.shipping.area = null;
      state.shipping.rates = [];
      renderRates();
      setShippingStatus("");
      renderCart();
    }
    window.clearTimeout(areaTimer);
    if (query.length < 3) {
      if (areaController) areaController.abort();
      closeAreaResults();
      return;
    }
    areaTimer = window.setTimeout(() => searchAreas(query), 350);
  });

  checkoutArea.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && !areaResults.hidden) {
      // Tutup daftar wilayah saja, jangan ikut menutup dialog keranjang.
      event.preventDefault();
      closeAreaResults();
    }
  });

  areaResults.addEventListener("click", (event) => {
    const option = event.target.closest("[data-area-index]");
    if (!option) return;
    const area = state.shipping.areas[Number(option.dataset.areaIndex)];
    if (area) selectArea(area);
  });

  cartDialog.addEventListener("click", (event) => {
    if (!event.target.closest(".area-field")) closeAreaResults();
  });

  checkoutCart.addEventListener("click", () => {
    if (checkoutCart.getAttribute("aria-disabled") === "true") {
      if (!state.submitting) showToast("Lengkapi data penerima, tujuan, dan kurir terlebih dahulu");
      return;
    }
    submitOrder();
  });

  orderPanel.addEventListener("click", (event) => {
    const pay = event.target.closest("[data-order-pay]");
    if (pay) {
      payOrder(pay);
      return;
    }
    if (event.target.closest("[data-order-refresh]")) {
      refreshOrder();
      return;
    }
    if (!event.target.closest("[data-order-close]")) return;
    state.order = null;
    cartDialog.close();
    renderCart();
  });

  document.addEventListener("click", (event) => {
    if (event.defaultPrevented || linkLoading) return;
    const link = event.target.closest("a[href]");
    if (!link) return;
    if (link.getAttribute("aria-disabled") === "true") return;

    const href = link.getAttribute("href");
    if (!href || href.startsWith("#") || href.startsWith("javascript:")) return;

    event.preventDefault();
    linkLoading = true;
    if (pageLoaderOverlay) pageLoaderOverlay.hidden = false;

    const target = link.getAttribute("target");
    const rel = link.getAttribute("rel") || "";
    const isExternalTab = target === "_blank";
    const useNoOpener = rel.includes("noopener") || rel.includes("noreferrer");

    window.setTimeout(() => {
      if (isExternalTab) {
        window.open(href, "_blank", useNoOpener ? "noopener,noreferrer" : "");
        if (pageLoaderOverlay) pageLoaderOverlay.hidden = true;
        linkLoading = false;
        return;
      }
      window.location.href = href;
    }, 5000);
  });

  window.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && dialog.open) closeProduct();
  });

  async function init() {
    resultCount.textContent = "Memuat produk...";
    try {
      products = await loadProducts();
    } catch (error) {
      console.error(error);
      showToast("Katalog gagal dimuat, coba muat ulang halaman");
    }
    renderStats();
    render();
    resumeOrderFromUrl();
  }

  init();
})();

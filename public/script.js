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
    idempotencyKey: null,
    // Voucher yang berhasil dicek: { code, label, type, value, minSubtotal, maxDiscount }.
    voucher: null,
    voucherCheckedSubtotal: null
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
  const imageViewerTrack = document.querySelector("#imageViewerTrack");
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
  const shippingMethod = document.querySelector("#shippingMethod");
  const pickupHint = document.querySelector("#pickupHint");
  const checkoutArea = document.querySelector("#checkoutArea");
  const areaResults = document.querySelector("#areaResults");
  const shippingService = document.querySelector("#shippingService");
  const shippingStatus = document.querySelector("#shippingStatus");
  const checkoutNote = document.querySelector("#checkoutNote");
  const noteHint = document.querySelector("#noteHint");
  const voucherCode = document.querySelector("#voucherCode");
  const applyVoucher = document.querySelector("#applyVoucher");
  const voucherStatus = document.querySelector("#voucherStatus");
  const cartDiscountRow = document.querySelector("#cartDiscountRow");
  const cartDiscountLabel = document.querySelector("#cartDiscountLabel");
  const cartDiscount = document.querySelector("#cartDiscount");
  const checkoutErrors = document.querySelector("#checkoutErrors");
  const mobileCartBar = document.querySelector("#mobileCartBar");
  const mobileCartSummary = document.querySelector("#mobileCartSummary");
  const pageLoaderOverlay = document.querySelector("#pageLoaderOverlay");
  const toast = document.querySelector("#toast");

  // Diperbarui dari Admin > Pengaturan saat katalog dimuat.
  let whatsappNumber = "6281236773427";
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
      imageSmall: escapeHtml(product.imageSmall || product.image),
      images: (product.images && product.images.length ? product.images : [{ src: product.image, small: product.imageSmall || product.image }])
        .map((photo) => ({ src: escapeHtml(photo.src), small: escapeHtml(photo.small || photo.src) }))
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
    if (data.shop && /^62\d{8,13}$/.test(data.shop.whatsapp || "")) {
      whatsappNumber = data.shop.whatsapp;
      // Tombol WhatsApp statis di header dan footer ikut memakai nomor admin terbaru.
      document.querySelectorAll('a[href^="https://wa.me/"]').forEach((link) => {
        link.href = link.href.replace(/wa\.me\/\d+/, `wa.me/${whatsappNumber}`);
      });
    }
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
            ${product.images.length > 1 ? `<span class="photo-count" aria-label="${product.images.length} foto">${product.images.length} foto</span>` : ""}
            <span class="price-badge">${shortPrice(product.price)}</span>
            <span class="status-badge${!isSoldOut && raw.stock <= lowStockThreshold ? " is-low" : ""}">${stockLabel(raw)}</span>
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
    if (shippingMethod.value === "pickup") return 0;
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
      const data = await postJson("/api/shipping-rates.php", { areaId: area.id, items }, ratesController.signal);
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
      const response = await fetch(`/api/shipping-areas.php?q=${encodeURIComponent(query)}`, {
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
    revalidateField(checkoutArea);
    refreshRates();
  }

  const lowStockThreshold = 3;

  function stockLabel(product) {
    if (product.status === "sold-out") return "Sold out";
    return product.stock <= lowStockThreshold ? `Sisa ${product.stock}` : "Ready";
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

  const pickupLabel = "Ambil di tempat";
  const storeMapUrl = "https://www.google.com/maps?q=-10.163101,123.618319";
  const storeAddress = "Jl Gedung Keuangan Gang 2 (Yourglow Kayu Putih)";

  // 6281234567890 -> 0812-3456-7890, lebih mudah dibaca dan diketik ulang.
  function formatPhone(number) {
    const local = String(number || "").replace(/^62/, "0");
    return local.replace(/^(\d{4})(\d{4})(\d+)$/, "$1-$2-$3");
  }

  function adminWhatsapp(order) {
    return order.payment && /^62\d{8,13}$/.test(order.payment.whatsapp || "") ? order.payment.whatsapp : whatsappNumber;
  }

  // Ringkasan teks pesanan: dipakai untuk pesan WhatsApp dan tombol "Salin detail pesanan".
  function orderDetailLines(order) {
    const pickup = order.courier === pickupLabel;
    return [
      `Nomor pesanan: ${order.code}`,
      `Status: ${orderStatusLabels[order.status] || order.status}`,
      "",
      ...order.items.map((item, index) => `${index + 1}. ${item.name} - ${item.series}, ${item.qty} x ${formatPrice(item.unitPrice)} = ${formatPrice(item.lineTotal)}`),
      "",
      `Subtotal: ${formatPrice(order.subtotal)}`,
      ...(order.discount ? [`Voucher ${order.voucherCode}: -${formatPrice(order.discount)}`] : []),
      pickup ? "Pengiriman: Ambil di tempat" : `Ongkir (${order.courier}): ${formatPrice(order.shippingCost)}`,
      ...(order.uniqueCode ? [`Kode unik: ${formatPrice(order.uniqueCode)}`] : []),
      `Total: ${formatPrice(order.total)}`,
      "",
      `Penerima: ${order.customerName}${order.customerPhone ? ` (${formatPhone(order.customerPhone)})` : ""}`,
      ...(!pickup && order.address ? [`Alamat: ${order.address}, ${order.areaName}`] : []),
      ...(pickup ? [`Alamat ambil: ${storeAddress}`] : []),
      ...(order.note ? [`Catatan: ${order.note}`] : [])
    ];
  }

  function orderWhatsappUrl(order) {
    const message = ["Halo Oleena, saya mau tanya tentang pesanan saya.", "", ...orderDetailLines(order)].join("\n");
    return `https://wa.me/${adminWhatsapp(order)}?text=${encodeURIComponent(message)}`;
  }

  function getOrderToken(code) {
    try {
      return JSON.parse(localStorage.getItem("oleena:orders") || "{}")[code] || null;
    } catch (error) {
      return null;
    }
  }

  // ---------- Pembayaran manual (QR / transfer toko + konfirmasi WhatsApp) ----------

  function formatServerDate(value) {
    if (!value) return "";
    const date = new Date(value.replace(" ", "T") + "+08:00");
    return Number.isNaN(date.getTime())
      ? value
      : `${new Intl.DateTimeFormat("id-ID", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }).format(date)} WITA`;
  }

  function paymentConfirmUrl(order) {
    const message = [
      `Halo Oleena, saya sudah membayar pesanan ${order.code}.`,
      `Nama: ${order.customerName}`,
      `Total dibayar: ${formatPrice(order.total)}`,
      "Bukti transfer saya lampirkan. Terima kasih!"
    ].join("\n");
    return `https://wa.me/${adminWhatsapp(order)}?text=${encodeURIComponent(message)}`;
  }

  async function copyText(text, label) {
    try {
      await navigator.clipboard.writeText(text);
      showToast(label);
    } catch (error) {
      showToast(text);
    }
  }

  function manualPaymentBox(order) {
    const pay = order.status === "pending_payment" ? order.payment : null;
    if (!pay) return "";
    const lastDigits = String(order.total % 1000).padStart(3, "0");
    const account = (pay.bankAccount || "").replace(/[^0-9]/g, "");
    return `
      <div class="pay-box">
        <p class="pay-label">Total yang harus dibayar</p>
        <div class="pay-amount">
          <strong>${formatPrice(order.total)}</strong>
          <button class="secondary-button" type="button" data-copy-text="${order.total}" data-copy-label="Nominal disalin">Salin</button>
        </div>
        <p class="pay-hint">Bayar <b>tepat</b> sampai 3 digit terakhir (<b>${lastDigits}</b>)${order.uniqueCode ? `, sudah termasuk kode unik ${formatPrice(order.uniqueCode)}` : ""}, supaya pembayaran cepat dicek.</p>
        ${pay.qr ? `
          <img class="pay-qr" src="${escapeHtml(pay.qr)}" alt="QR pembayaran Oleena" width="280" height="280">
          <a class="secondary-button" href="${escapeHtml(pay.qr)}" download="QR-Oleena-${escapeHtml(order.code)}.png">Simpan gambar QR</a>
          <p class="pay-hint">Scan dengan aplikasi bank atau e-wallet. Kalau membayar dari HP ini, simpan gambar QR lalu pilih "scan dari galeri".</p>` : ""}
        ${account ? `
          <div class="pay-bank">
            <div><span>${escapeHtml(pay.bankName || "Transfer bank")}</span><strong>${escapeHtml(pay.bankAccount)}</strong>${pay.bankHolder ? `<small>a.n. ${escapeHtml(pay.bankHolder)}</small>` : ""}</div>
            <button class="secondary-button" type="button" data-copy-text="${account}" data-copy-label="Nomor rekening disalin">Salin</button>
          </div>` : ""}
        ${pay.note ? `<p class="pay-note">${escapeHtml(pay.note)}</p>` : ""}
      </div>`;
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

  function orderSteps(order) {
    if (order.status !== "pending_payment" || !order.payment) return "";
    return `
      <ol class="order-steps">
        <li class="is-current"><b>Bayar ${formatPrice(order.total)}</b><span>Lewat QR atau transfer di bawah, sebelum ${escapeHtml(formatServerDate(order.expiresAt))}.</span></li>
        <li><b>Kirim bukti bayar</b><span>Tekan tombol hijau "Kirim bukti bayar via WhatsApp".</span></li>
        <li><b>Pesanan kami proses</b><span>Nomor resi dikabarkan lewat WhatsApp.</span></li>
      </ol>`;
  }

  function renderOrderPanel() {
    const order = state.order;
    orderPanel.hidden = !order;
    cartFooter.hidden = Boolean(order);
    if (!order) return;
    cartItems.hidden = true;
    cartEmpty.hidden = true;

    const pickup = order.courier === pickupLabel;
    const pendingManual = order.status === "pending_payment" && order.payment;
    const adminNumber = adminWhatsapp(order);
    const itemCount = order.items.reduce((sum, item) => sum + item.qty, 0);
    const items = order.items.map((item) => `
      <li>
        <div><strong>${escapeHtml(item.name)}</strong><small>${escapeHtml(item.series)} &middot; ${item.qty} &times; ${formatPrice(item.unitPrice)}</small></div>
        <strong>${formatPrice(item.lineTotal)}</strong>
      </li>
    `).join("");
    orderPanel.innerHTML = `
      <div class="order-success">
        <span class="order-check" aria-hidden="true">&#10003;</span>
        <div>
          <h3>Pesanan berhasil dibuat</h3>
          <p>Terima kasih, ${escapeHtml(order.customerName)}! Simpan nomor pesanan untuk cek status kapan saja.</p>
        </div>
      </div>

      <div class="order-code-box">
        <div>
          <span>Nomor pesanan</span>
          <strong>${escapeHtml(order.code)}</strong>
        </div>
        <button class="secondary-button" type="button" data-copy-text="${escapeHtml(order.code)}" data-copy-label="Nomor pesanan disalin">Salin</button>
      </div>
      <p class="order-status">${escapeHtml(orderStatusLabels[order.status] || order.status)}</p>

      ${orderSteps(order)}
      ${manualPaymentBox(order)}
      ${pendingManual ? `<a class="primary-button whatsapp-button" href="${paymentConfirmUrl(order)}" target="_blank" rel="noreferrer">Kirim bukti bayar via WhatsApp</a>` : ""}
      ${order.status === "pending_payment" && order.paymentMethod === "midtrans" ? `
        <button class="primary-button" type="button" data-order-pay>Bayar sekarang</button>
      ` : ""}

      <section class="order-section">
        <h4>Rincian pembelian</h4>
        <ul class="order-lines">${items}</ul>
        <dl class="order-summary">
          <div><dt>Subtotal (${itemCount} barang)</dt><dd>${formatPrice(order.subtotal)}</dd></div>
          ${order.discount ? `<div class="is-discount"><dt>Voucher ${escapeHtml(order.voucherCode)}</dt><dd>-${formatPrice(order.discount)}</dd></div>` : ""}
          <div><dt>${pickup ? "Ambil di tempat" : "Ongkir"}</dt><dd>${pickup ? "Gratis" : formatPrice(order.shippingCost)}</dd></div>
          ${order.uniqueCode ? `<div><dt>Kode unik</dt><dd>${formatPrice(order.uniqueCode)}</dd></div>` : ""}
          <div class="is-total"><dt>Total bayar</dt><dd>${formatPrice(order.total)}</dd></div>
        </dl>
      </section>

      <section class="order-section">
        <h4>${pickup ? "Pengambilan" : "Pengiriman"}</h4>
        <dl class="order-details">
          <div><dt>Penerima</dt><dd>${escapeHtml(order.customerName)}</dd></div>
          ${order.customerPhone ? `<div><dt>No WhatsApp</dt><dd>${escapeHtml(formatPhone(order.customerPhone))}</dd></div>` : ""}
          ${pickup
            ? `<div><dt>Lokasi</dt><dd>${escapeHtml(storeAddress)}<br><a href="${storeMapUrl}" target="_blank" rel="noreferrer">Buka di Google Maps</a></dd></div>`
            : `${order.address ? `<div><dt>Alamat</dt><dd>${escapeHtml(order.address)}</dd></div>` : ""}
               <div><dt>Wilayah</dt><dd>${escapeHtml(order.areaName)}</dd></div>
               <div><dt>Kurir</dt><dd>${escapeHtml(order.courier)}</dd></div>`}
          ${order.trackingNumber ? `<div><dt>No resi</dt><dd>${escapeHtml(order.trackingNumber)}</dd></div>` : ""}
          ${order.note ? `<div><dt>${pickup ? "Jam datang" : "Catatan"}</dt><dd>${escapeHtml(order.note)}</dd></div>` : ""}
        </dl>
      </section>

      <section class="order-contact">
        <h4>Butuh bantuan?</h4>
        <p>Admin Oleena siap membantu lewat WhatsApp atau telepon.</p>
        <a class="order-contact-number" href="tel:+${adminNumber}">${escapeHtml(formatPhone(adminNumber))}</a>
        <div class="order-contact-actions">
          <a class="secondary-button" href="${orderWhatsappUrl(order)}" target="_blank" rel="noreferrer">Chat WhatsApp</a>
          <a class="secondary-button" href="tel:+${adminNumber}">Telepon</a>
        </div>
      </section>

      <div class="order-actions">
        <button class="secondary-button" type="button" data-order-image>Simpan sebagai gambar</button>
        <button class="secondary-button" type="button" data-order-copy>Salin detail pesanan</button>
        ${order.status === "pending_payment" ? '<button class="secondary-button" type="button" data-order-refresh>Cek status pembayaran</button>' : ""}
        <a class="secondary-button" href="lacak.html?kode=${encodeURIComponent(order.code)}">Lacak pesanan</a>
        <button class="secondary-button" type="button" data-order-close>Belanja lagi</button>
      </div>
    `;
  }

  // ---------- Simpan detail pesanan sebagai gambar PNG ----------
  // Digambar langsung di canvas (bukan screenshot DOM) supaya hasilnya rapi seperti struk dan tidak butuh library luar.

  function loadImage(src) {
    return new Promise((resolve) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = () => resolve(null);
      image.src = src;
    });
  }

  function wrapText(ctx, text, maxWidth) {
    const lines = [];
    String(text).split("\n").forEach((paragraph) => {
      let line = "";
      paragraph.split(" ").forEach((word) => {
        const next = line ? `${line} ${word}` : word;
        if (line && ctx.measureText(next).width > maxWidth) {
          lines.push(line);
          line = word;
        } else {
          line = next;
        }
      });
      lines.push(line);
    });
    return lines;
  }

  async function orderImageBlob(order) {
    const width = 720;
    const pad = 40;
    const inner = width - pad * 2;
    const scale = 2;
    const font = getComputedStyle(document.body).fontFamily;
    const canvas = document.createElement("canvas");
    canvas.width = width * scale;
    canvas.height = 5000 * scale;
    const ctx = canvas.getContext("2d");
    ctx.scale(scale, scale);
    ctx.fillStyle = "#ffffff";
    ctx.fillRect(0, 0, width, 5000);
    ctx.textBaseline = "top";

    if (document.fonts && document.fonts.ready) await document.fonts.ready;
    let y = pad;
    const setFont = (size, weight = 400, color = "#111111") => {
      ctx.font = `${weight} ${size}px ${font}`;
      ctx.fillStyle = color;
    };
    const text = (value, x, size, weight, color, align = "left") => {
      setFont(size, weight, color);
      ctx.textAlign = align;
      ctx.fillText(value, x, y);
      ctx.textAlign = "left";
    };
    const rule = (gap = 18) => {
      y += gap;
      ctx.fillStyle = "#e7e7e7";
      ctx.fillRect(pad, y, inner, 1);
      y += gap;
    };
    // Baris label-nilai; nilai panjang dibungkus ke baris berikutnya di kolom kanan.
    const row = (label, value, { bold = false, color = "#111111", size = 16 } = {}) => {
      setFont(size, 400, "#767676");
      ctx.fillText(label, pad, y);
      setFont(size, bold ? 700 : 600, color);
      const lines = wrapText(ctx, value, inner * 0.62);
      ctx.textAlign = "right";
      lines.forEach((line, index) => ctx.fillText(line, width - pad, y + index * (size + 8)));
      ctx.textAlign = "left";
      y += lines.length * (size + 8) + 6;
    };

    const logo = await loadImage("assets/logo-oleena.png");
    if (logo) {
      const logoHeight = 52;
      const logoWidth = (logo.naturalWidth / logo.naturalHeight) * logoHeight;
      ctx.drawImage(logo, (width - logoWidth) / 2, y, logoWidth, logoHeight);
      y += logoHeight + 18;
    }
    text("Detail Pesanan", width / 2, 15, 600, "#767676", "center");
    y += 26;
    text(order.code, width / 2, 30, 800, "#111111", "center");
    y += 44;
    const status = orderStatusLabels[order.status] || order.status;
    text(order.createdAt ? `${status} \u00b7 ${formatServerDate(order.createdAt)}` : status, width / 2, 15, 600, "#b5071e", "center");
    y += 22;

    rule();
    text("Rincian pembelian", pad, 17, 700);
    y += 32;
    order.items.forEach((item) => {
      setFont(16, 600);
      const names = wrapText(ctx, item.name, inner * 0.68);
      names.forEach((line, index) => ctx.fillText(line, pad, y + index * 22));
      ctx.textAlign = "right";
      ctx.fillText(formatPrice(item.lineTotal), width - pad, y);
      ctx.textAlign = "left";
      y += names.length * 22 + 2;
      text(`${item.series} \u00b7 ${item.qty} \u00d7 ${formatPrice(item.unitPrice)}`, pad, 14, 400, "#767676");
      y += 30;
    });

    rule(10);
    const pickup = order.courier === pickupLabel;
    const itemCount = order.items.reduce((sum, item) => sum + item.qty, 0);
    row(`Subtotal (${itemCount} barang)`, formatPrice(order.subtotal));
    if (order.discount) row(`Voucher ${order.voucherCode}`, `-${formatPrice(order.discount)}`, { color: "#b5071e" });
    row(pickup ? "Ambil di tempat" : `Ongkir (${order.courier})`, pickup ? "Gratis" : formatPrice(order.shippingCost));
    if (order.uniqueCode) row("Kode unik", formatPrice(order.uniqueCode));
    y += 4;
    row("Total bayar", formatPrice(order.total), { bold: true, size: 20 });

    rule(14);
    text(pickup ? "Pengambilan" : "Pengiriman", pad, 17, 700);
    y += 32;
    row("Penerima", order.customerName);
    if (order.customerPhone) row("No WhatsApp", formatPhone(order.customerPhone));
    if (pickup) {
      row("Alamat ambil", storeAddress);
    } else {
      if (order.address) row("Alamat", order.address);
      row("Wilayah", order.areaName);
      row("Kurir", order.courier);
    }
    if (order.trackingNumber) row("No resi", order.trackingNumber);
    if (order.note) row(pickup ? "Jam datang" : "Catatan", order.note);

    rule(14);
    text(`Admin Oleena: ${formatPhone(adminWhatsapp(order))} (WhatsApp)`, width / 2, 14, 600, "#767676", "center");
    y += 20 + pad;

    const output = document.createElement("canvas");
    output.width = width * scale;
    output.height = Math.ceil(y * scale);
    output.getContext("2d").drawImage(canvas, 0, 0);
    return new Promise((resolve) => output.toBlob(resolve, "image/png"));
  }

  async function saveOrderImage(button) {
    const order = state.order;
    const label = button.textContent;
    button.disabled = true;
    button.textContent = "Membuat gambar...";
    try {
      const blob = await orderImageBlob(order);
      if (!blob) throw new Error("Gagal membuat gambar");
      const filename = `Pesanan-Oleena-${order.code}.png`;
      const file = typeof File === "function" ? new File([blob], filename, { type: "image/png" }) : null;
      // Di HP, menu bagikan memudahkan menyimpan ke galeri atau langsung kirim lewat WhatsApp.
      const touch = window.matchMedia && window.matchMedia("(pointer: coarse)").matches;
      if (touch && file && navigator.canShare && navigator.canShare({ files: [file] })) {
        try {
          await navigator.share({ files: [file], title: filename });
          return;
        } catch (error) {
          if (error.name === "AbortError") return;
        }
      }
      const url = URL.createObjectURL(blob);
      const link = document.createElement("a");
      link.href = url;
      link.download = filename;
      document.body.appendChild(link);
      link.click();
      link.remove();
      setTimeout(() => URL.revokeObjectURL(url), 10000);
      showToast("Gambar pesanan disimpan");
    } catch (error) {
      showToast(error.message || "Gagal membuat gambar");
    } finally {
      button.disabled = false;
      button.textContent = label;
    }
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

  // crypto.randomUUID belum ada di browser lama (mis. Safari iOS < 15.4 dan sebagian browser bawaan aplikasi).
  function newIdempotencyKey() {
    if (window.crypto && typeof window.crypto.randomUUID === "function") return window.crypto.randomUUID();
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, "0")).join("");
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }

  async function submitOrder() {
    if (state.submitting) return;
    const pickup = shippingMethod.value === "pickup";
    state.submitting = true;
    checkoutCart.textContent = "Memproses...";
    checkoutCart.setAttribute("aria-disabled", "true");
    // Semua langkah di dalam try: kalau ada yang gagal, pembeli melihat pesan dan tombol bisa ditekan lagi.
    try {
      const rate = pickup ? null : getSelectedRate();
      state.idempotencyKey = state.idempotencyKey || newIdempotencyKey();
      const data = await postJson("api/orders.php", {
        idempotencyKey: state.idempotencyKey,
        customer: {
          name: checkoutName.value,
          phone: checkoutPhone.value,
          address: checkoutAddress.value,
          note: checkoutNote.value
        },
        shipping: pickup
          ? { method: "pickup" }
          : { method: "delivery", areaId: state.shipping.area.id, areaName: state.shipping.area.name, rateKey: rate.key },
        items: Array.from(state.cart.entries()).map(([id, qty]) => ({ id, qty })),
        voucherCode: state.voucher && voucherDiscount(getCartTotal()) > 0 ? state.voucher.code : ""
      });
      saveOrderToken(data.order.code, data.accessToken);
      state.order = data.order;
      state.idempotencyKey = null;
      state.cart.clear();
      try {
        localStorage.setItem("oleena:cart", "[]");
      } catch (error) {
        console.warn(error);
      }
      checkoutNote.value = "";
      state.voucher = null;
      voucherCode.value = "";
      setVoucherStatus("");
      showCheckoutErrors([]);
      syncAllProductCartButtons();
    } catch (error) {
      showServerError(error.message);
      if (/kurir/i.test(error.message)) refreshRates();
    } finally {
      state.submitting = false;
      checkoutCart.textContent = "Buat pesanan";
      renderCart();
      // Form checkout ada di bawah: setelah pesanan dibuat, gulir keranjang ke paling atas supaya ringkasan terlihat.
      if (state.order) orderPanel.closest(".cart-shell").scrollTop = 0;
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
    if ((state.cart.get(productId) || 0) >= product.stock) {
      showToast(`Stok ${product.name} tinggal ${product.stock}, semuanya sudah di keranjang`);
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
    const product = products.find((item) => item.id === productId);
    if (delta > 0 && product && current >= product.stock) {
      showToast(`Stok ${product.name} tinggal ${product.stock}`);
      return;
    }
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

  // ---------- Voucher ----------

  function voucherDiscount(subtotal) {
    const voucher = state.voucher;
    if (!voucher || subtotal < voucher.minSubtotal) return 0;
    let discount = voucher.type === "percent" ? Math.floor(subtotal * voucher.value / 100) : voucher.value;
    if (voucher.type === "percent" && voucher.maxDiscount > 0) discount = Math.min(discount, voucher.maxDiscount);
    return Math.max(0, Math.min(discount, subtotal));
  }

  function setVoucherStatus(message, isError = false) {
    voucherStatus.textContent = message;
    voucherStatus.classList.toggle("is-error", isError);
    voucherCode.setAttribute("aria-invalid", isError ? "true" : "false");
  }

  function renderVoucherStatus(subtotal) {
    const voucher = state.voucher;
    applyVoucher.textContent = voucher ? "Hapus" : "Pakai";
    voucherCode.readOnly = Boolean(voucher);
    if (!voucher) {
      // Pesan gagal lama (mis. kurang dari minimal belanja) tidak berlaku lagi setelah isi keranjang berubah.
      if (state.voucherCheckedSubtotal !== null && state.voucherCheckedSubtotal !== subtotal && voucherStatus.classList.contains("is-error")) {
        state.voucherCheckedSubtotal = null;
        setVoucherStatus("Isi keranjang berubah. Tekan Pakai untuk cek voucher lagi.");
      }
      return;
    }
    if (subtotal < voucher.minSubtotal) {
      setVoucherStatus(`Voucher ${voucher.code} berlaku untuk belanja minimal ${formatPrice(voucher.minSubtotal)}. Tambah ${formatPrice(voucher.minSubtotal - subtotal)} lagi supaya potongan berlaku.`, true);
    } else {
      setVoucherStatus(`Voucher ${voucher.code} dipakai: ${voucher.label}. Hemat ${formatPrice(voucherDiscount(subtotal))}.`);
    }
  }

  function removeVoucher(message = "") {
    state.voucher = null;
    voucherCode.value = "";
    setVoucherStatus(message, Boolean(message));
    renderCart();
  }

  async function checkVoucher() {
    if (state.voucher) {
      removeVoucher();
      return;
    }
    const code = voucherCode.value.trim().toUpperCase();
    if (code.length < 3) {
      setVoucherStatus("Ketik kode voucher dulu, minimal 3 huruf.", true);
      voucherCode.focus();
      return;
    }
    applyVoucher.disabled = true;
    setVoucherStatus("Mengecek voucher...");
    try {
      const data = await postJson("api/voucher.php", {
        code,
        phone: checkoutPhone.value,
        items: Array.from(state.cart.entries()).map(([id, qty]) => ({ id, qty }))
      });
      state.voucher = data.voucher;
      voucherCode.value = data.voucher.code;
    } catch (error) {
      state.voucher = null;
      state.voucherCheckedSubtotal = getCartTotal();
      setVoucherStatus(error.message, true);
    } finally {
      applyVoucher.disabled = false;
      renderCart();
    }
  }

  // ---------- Validasi data pembeli ----------
  // Aturan sama dengan server (src/orders.php), dengan pesan yang menjelaskan cara memperbaikinya.

  function fieldError(field) {
    const id = `${field.id}Error`;
    let element = document.getElementById(id);
    if (!element) {
      element = document.createElement("small");
      element.id = id;
      element.className = "field-error";
      element.hidden = true;
      (field.closest("label") || field.closest(".area-field")).append(element);
    }
    return element;
  }

  function setFieldError(field, message) {
    const element = fieldError(field);
    element.textContent = message || "";
    element.hidden = !message;
    field.setAttribute("aria-invalid", message ? "true" : "false");
    if (message) field.setAttribute("aria-describedby", element.id);
  }

  function phoneProblem(value) {
    const raw = value.replace(/[\s.()-]/g, "");
    if (!raw) return "Nomor WhatsApp belum diisi. Contoh: 081234567890.";
    if (!/^\+?\d+$/.test(raw)) return "Nomor WhatsApp hanya boleh berisi angka. Contoh: 081234567890.";
    const match = raw.match(/^(?:\+62|62|0)(\d*)$/);
    if (!match) return "Nomor WhatsApp harus diawali 08 atau +62. Contoh: 081234567890.";
    if (!match[1].startsWith("8")) return "Gunakan nomor HP (diawali 08), bukan nomor telepon rumah. Contoh: 081234567890.";
    const digits = match[1].length + 1;
    if (digits < 10) return `Nomor WhatsApp terlalu pendek (baru ${digits} angka). Nomor HP biasanya 10-13 angka, contoh: 081234567890.`;
    if (digits > 14) return `Nomor WhatsApp terlalu panjang (${digits} angka). Periksa lagi, mungkin ada angka yang dobel.`;
    return "";
  }

  function addressProblem(value) {
    const text = value.trim().replace(/\s+/g, " ");
    const example = "Contoh: Jl. Melati No. 5, RT 02/RW 01, Oebobo.";
    if (!text) return `Alamat belum diisi. Tulis nama jalan, nomor rumah, RT/RW, dan kelurahan. ${example}`;
    if (text.length < 15) return `Alamat terlalu pendek, kurir bisa salah antar. Tulis lengkap: nama jalan, nomor rumah, RT/RW, dan kelurahan. ${example}`;
    if (text.length > 500) return "Alamat terlalu panjang (maksimal 500 huruf).";
    return "";
  }

  function checkoutProblems() {
    const pickup = shippingMethod.value === "pickup";
    const name = checkoutName.value.trim();
    const problems = [
      [checkoutName, !name ? "Nama penerima belum diisi." : name.length < 2 ? "Nama penerima terlalu pendek, tulis nama lengkap." : ""],
      [checkoutPhone, phoneProblem(checkoutPhone.value)]
    ];
    if (!pickup) {
      problems.push([checkoutAddress, addressProblem(checkoutAddress.value)]);
      problems.push([checkoutArea, state.shipping.area ? "" : checkoutArea.value.trim()
        ? "Wilayah belum dipilih. Ketik nama kecamatan/kota, lalu ketuk salah satu pilihan di daftar."
        : "Wilayah tujuan belum diisi. Ketik nama kecamatan/kota, lalu pilih dari daftar."]);
      if (state.shipping.area) problems.push([shippingService, getSelectedRate() ? "" : "Pilih kurir pengiriman."]);
    }
    return problems;
  }

  function showCheckoutErrors(messages) {
    checkoutErrors.hidden = messages.length === 0;
    checkoutErrors.innerHTML = messages.length ? `
      <strong>Pesanan belum bisa dibuat.</strong>
      ${messages.length > 1 ? "<span>Perbaiki isian yang ditandai merah:</span>" : ""}
      <ul>${messages.map((message) => `<li>${escapeHtml(message)}</li>`).join("")}</ul>` : "";
  }

  // Tampilkan semua kesalahan sekaligus, lalu arahkan ke kolom pertama yang perlu diperbaiki.
  function validateCheckout() {
    const problems = checkoutProblems();
    problems.forEach(([field, message]) => setFieldError(field, message));
    const failed = problems.filter(([, message]) => message);
    showCheckoutErrors(failed.map(([, message]) => message));
    if (failed.length) {
      const first = failed[0][0];
      first.scrollIntoView({ block: "center" });
      first.focus({ preventScroll: true });
    }
    return failed.length === 0;
  }

  // Kesalahan dari server ditampilkan di kolom yang bersangkutan bila bisa dikenali.
  function showServerError(message) {
    const target = /voucher/i.test(message) ? null
      : /whatsapp/i.test(message) ? checkoutPhone
      : /alamat/i.test(message) ? checkoutAddress
      : /nama penerima/i.test(message) ? checkoutName
      : /wilayah/i.test(message) ? checkoutArea
      : /kurir/i.test(message) ? shippingService
      : null;
    if (/voucher/i.test(message)) {
      state.voucher = null;
      setVoucherStatus(message, true);
      renderCart();
    }
    if (target) setFieldError(target, message);
    showCheckoutErrors([message]);
    checkoutErrors.scrollIntoView({ block: "center" });
  }

  // Kesalahan yang sudah ditandai hilang begitu kolomnya diperbaiki.
  function revalidateField(field) {
    if (field.getAttribute("aria-invalid") !== "true") return;
    const problem = checkoutProblems().find(([candidate]) => candidate === field);
    setFieldError(field, problem ? problem[1] : "");
    const remaining = checkoutProblems().filter(([candidate, message]) => message && candidate.getAttribute("aria-invalid") === "true");
    showCheckoutErrors(remaining.map(([, message]) => message));
  }

  function renderCart() {
    const items = getCartProducts();
    const itemCount = getCartItemCount();
    const subtotal = getCartTotal();
    const shipping = parseShippingValue();
    const discount = voucherDiscount(subtotal);
    const grandTotal = subtotal - discount + shipping;

    cartCount.textContent = itemCount;
    mobileCartSummary.textContent = `${itemCount} item - ${formatPrice(grandTotal)}`;
    mobileCartBar.hidden = itemCount === 0;
    openCart.setAttribute("aria-label", `Buka keranjang, ${itemCount} item`);
    cartEmpty.hidden = items.length > 0;
    cartItems.hidden = items.length === 0;
    clearCart.disabled = items.length === 0;
    cartSubtotal.textContent = formatPrice(subtotal);
    cartShipping.textContent = formatPrice(shipping);
    cartDiscountRow.hidden = discount === 0;
    if (state.voucher) cartDiscountLabel.textContent = `Voucher ${state.voucher.code}`;
    cartDiscount.textContent = `-${formatPrice(discount)}`;
    cartTotal.textContent = formatPrice(grandTotal);
    renderVoucherStatus(subtotal);
    // Data yang belum lengkap tidak mengunci tombol: saat ditekan, kolom yang salah ditandai dengan penjelasan.
    checkoutCart.setAttribute("aria-disabled", items.length === 0 || state.submitting ? "true" : "false");

    cartItems.innerHTML = items.map(escapeProduct).map((product) => `
      <article class="cart-item">
        <img src="${product.image}" alt="${product.name} - ${product.series}">
        <div class="cart-item-body">
          <h3>${product.name}</h3>
          <p>${product.series}</p>
          <strong>${formatPrice(product.price * product.qty)}${product.qty > 1 ? ` <small>(${product.qty} × ${formatPrice(product.price)})</small>` : ""}</strong>
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
    // Dialog modal berada di lapisan paling atas, jadi toast dipindah ke dalam dialog yang sedang terbuka supaya terlihat.
    const host = document.querySelector("dialog[open]") || document.body;
    if (toast.parentElement !== host) host.append(toast);
    toast.textContent = message;
    toast.classList.add("is-visible");
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => toast.classList.remove("is-visible"), Math.min(6000, Math.max(1800, message.length * 60)));
  }

  // ---------- Galeri foto (swipe) ----------

  function gallerySlides(product, zoomable) {
    const total = product.images.length;
    return product.images.map((photo, index) => {
      const img = `<img src="${photo.src}" width="1440" height="1920" alt="${product.name} - foto ${index + 1} dari ${total}"${index ? ' loading="lazy"' : ""} decoding="async">`;
      return zoomable
        ? `<button class="gallery-slide" type="button" data-zoom-index="${index}" aria-label="Perbesar foto ${index + 1} dari ${total}">${img}</button>`
        : `<div class="gallery-slide">${img}</div>`;
    }).join("");
  }

  function galleryMarkup(product) {
    const total = product.images.length;
    const controls = total > 1 ? `
      <button class="gallery-nav is-prev" type="button" data-gallery-step="-1" aria-label="Foto sebelumnya">&lsaquo;</button>
      <button class="gallery-nav is-next" type="button" data-gallery-step="1" aria-label="Foto berikutnya">&rsaquo;</button>
      <div class="gallery-dots" aria-hidden="true">${product.images.map((_, index) => `<span${index ? "" : ' class="is-active"'}></span>`).join("")}</div>
      <span class="gallery-count" aria-live="polite">1/${total}</span>` : "";
    return `
      <div class="gallery" data-gallery>
        <div class="gallery-track">${gallerySlides(product, true)}</div>
        ${controls}
        <span class="zoom-hint">Ketuk untuk perbesar</span>
      </div>`;
  }

  function galleryIndex(track) {
    return track.clientWidth ? Math.round(track.scrollLeft / track.clientWidth) : 0;
  }

  // Titik indikator dan nomor foto mengikuti posisi geser.
  function setupGallery(root) {
    const track = root.querySelector(".gallery-track");
    let frame = 0;
    const update = () => {
      frame = 0;
      const index = galleryIndex(track);
      root.querySelectorAll(".gallery-dots span").forEach((dot, i) => dot.classList.toggle("is-active", i === index));
      const count = root.querySelector(".gallery-count");
      if (count) count.textContent = `${index + 1}/${track.children.length}`;
      root.classList.toggle("is-first", index === 0);
      root.classList.toggle("is-last", index === track.children.length - 1);
    };
    track.addEventListener("scroll", () => {
      if (!frame) frame = window.requestAnimationFrame(update);
    }, { passive: true });
    update();
  }

  function goToSlide(track, index, smooth = true) {
    const last = track.children.length - 1;
    const target = Math.max(0, Math.min(last, index));
    track.scrollTo({ left: target * track.clientWidth, behavior: smooth && !reduceMotion.matches ? "smooth" : "auto" });
  }

  function openProduct(raw) {
    const product = escapeProduct(raw);
    state.dialogProduct = product;
    dialogContent.innerHTML = `
      ${galleryMarkup(product)}
      <section class="dialog-details">
        <p class="eyebrow">${product.series}</p>
        <h2>${product.name}</h2>
        <div class="dialog-meta">
          <span class="pill">${formatPrice(product.price)}</span>
          <span class="pill">${product.status === "sold-out" ? "Sold out" : raw.stock <= lowStockThreshold ? `Stok tinggal ${raw.stock}` : "Ready stock"}</span>
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
    const gallery = dialogContent.querySelector("[data-gallery]");
    gallery.querySelector(".gallery-track").scrollLeft = 0;
    setupGallery(gallery);
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

  function openImageViewer(product, index) {
    imageViewerTrack.innerHTML = gallerySlides(product, false);
    if (typeof imageViewer.showModal === "function") imageViewer.showModal();
    else imageViewer.setAttribute("open", "");
    window.requestAnimationFrame(() => {
      goToSlide(imageViewerTrack, index, false);
      imageViewerTrack.dispatchEvent(new Event("scroll"));
    });
  }

  setupGallery(imageViewer.querySelector("[data-gallery]"));

  function closeImage() {
    if (imageViewer.open && typeof imageViewer.close === "function") imageViewer.close();
    else imageViewer.removeAttribute("open");
  }

  closeImageViewer.addEventListener("click", closeImage);
  imageViewer.addEventListener("click", (event) => {
    if (event.target === imageViewer) closeImage();
  });

  dialog.addEventListener("click", (event) => {
    const step = event.target.closest("[data-gallery-step]");
    if (step) {
      goToSlide(step.closest("[data-gallery]").querySelector(".gallery-track"), galleryIndex(step.closest("[data-gallery]").querySelector(".gallery-track")) + Number(step.dataset.galleryStep));
      return;
    }

    const zoom = event.target.closest("[data-zoom-index]");
    if (zoom && state.dialogProduct) {
      openImageViewer(state.dialogProduct, Number(zoom.dataset.zoomIndex));
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
      revalidateField(field);
      renderCart();
    });
  });

  // Nomor dan alamat langsung dicek begitu pembeli selesai mengisi, supaya salah ketik ketahuan lebih awal.
  [[checkoutPhone, phoneProblem], [checkoutAddress, addressProblem]].forEach(([field, problem]) => {
    field.addEventListener("blur", () => {
      if (!field.value.trim()) return;
      if (field === checkoutAddress && shippingMethod.value === "pickup") return;
      setFieldError(field, problem(field.value));
    });
  });

  shippingMethod.addEventListener("change", () => {
    const pickup = shippingMethod.value === "pickup";
    pickupHint.hidden = !pickup;
    // Ambil di tempat: pembeli yang datang, jadi catatan dipakai untuk jam kedatangan, bukan patokan rumah.
    noteHint.hidden = !pickup;
    checkoutNote.placeholder = pickup ? "Contoh: datang jam 16.00" : "Warna cadangan, patokan rumah, jam kirim, dll";
    checkoutAddress.required = !pickup;
    checkoutArea.closest(".area-field").hidden = pickup;
    shippingService.closest("label").hidden = pickup;
    if (pickup) {
      setShippingStatus("");
      [checkoutAddress, checkoutArea, shippingService].forEach((field) => setFieldError(field, ""));
      showCheckoutErrors([]);
    }
    renderCart();
  });

  shippingService.addEventListener("change", () => {
    revalidateField(shippingService);
    renderCart();
  });

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
      if (!state.submitting) showCheckoutErrors(["Keranjang masih kosong. Pilih produk dari katalog dulu."]);
      return;
    }
    if (validateCheckout()) submitOrder();
  });

  applyVoucher.addEventListener("click", checkVoucher);
  voucherCode.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      checkVoucher();
    }
  });
  voucherCode.addEventListener("input", () => {
    if (!state.voucher && voucherStatus.classList.contains("is-error")) setVoucherStatus("");
  });

  orderPanel.addEventListener("click", (event) => {
    const copy = event.target.closest("[data-copy-text]");
    if (copy) {
      copyText(copy.dataset.copyText, copy.dataset.copyLabel || "Disalin");
      return;
    }
    if (event.target.closest("[data-order-copy]")) {
      copyText(["Pesanan Oleena", ...orderDetailLines(state.order)].join("\n"), "Detail pesanan disalin");
      return;
    }
    const image = event.target.closest("[data-order-image]");
    if (image) {
      saveOrderImage(image);
      return;
    }
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
    // Link ke halaman situs sendiri (misalnya Lacak pesanan) langsung dibuka tanpa overlay.
    if (link.origin === window.location.origin) return;

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
    if (event.key === "ArrowLeft" || event.key === "ArrowRight") {
      const open = imageViewer.open ? imageViewer : dialog.open ? dialog : null;
      const track = open && open.querySelector(".gallery-track");
      if (track) goToSlide(track, galleryIndex(track) + (event.key === "ArrowRight" ? 1 : -1));
    }
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

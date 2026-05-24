(function () {
  const products = window.OLEENA_PRODUCTS || [];
  const state = {
    query: "",
    series: "all",
    status: "all",
    sort: "featured",
    visibleCount: 8,
    favorites: new Set(JSON.parse(localStorage.getItem("oleena:favorites") || "[]")),
    cart: new Map(JSON.parse(localStorage.getItem("oleena:cart") || "[]"))
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
  const openCart = document.querySelector("#openCart");
  const cartCount = document.querySelector("#cartCount");
  const cartDialog = document.querySelector("#cartDialog");
  const closeCart = document.querySelector("#closeCart");
  const cartItems = document.querySelector("#cartItems");
  const cartEmpty = document.querySelector("#cartEmpty");
  const cartTotal = document.querySelector("#cartTotal");
  const checkoutCart = document.querySelector("#checkoutCart");
  const clearCart = document.querySelector("#clearCart");
  const mobileCartBar = document.querySelector("#mobileCartBar");
  const mobileCartSummary = document.querySelector("#mobileCartSummary");
  const toast = document.querySelector("#toast");

  const whatsappNumber = "6281236773427";
  const productBatchSize = 8;
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
      <button class="segment-button" type="button" data-series="${value}" aria-pressed="${state.series === value}">
        <span>${label}</span>
        <span class="segment-count">${count}</span>
      </button>
    `).join("");
  }

  function renderProducts() {
    const list = getFilteredProducts();
    const visibleList = list.slice(0, state.visibleCount);
    const remainingCount = Math.max(list.length - visibleList.length, 0);
    const currentSeries = state.series === "all" ? "Semua Produk" : state.series;
    resultTitle.textContent = currentSeries;
    resultCount.textContent = remainingCount > 0 ? `${visibleList.length} dari ${list.length} item` : `${list.length} item`;
    emptyState.hidden = list.length > 0;
    loadMoreWrap.hidden = remainingCount === 0;
    loadMoreProducts.textContent = remainingCount > productBatchSize ? `Lihat ${productBatchSize} lagi` : `Lihat ${remainingCount} lagi`;

    productGrid.innerHTML = visibleList.map((product) => {
      const isFavorite = state.favorites.has(product.id);
      const isSoldOut = product.status === "sold-out";
      const cartQty = state.cart.get(product.id) || 0;
      return `
        <article class="product-card ${isSoldOut ? "is-sold-out" : ""}">
          <div class="product-media">
            <img src="${product.image}" alt="${product.name} - ${product.series}" loading="lazy">
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
              <span class="tone-chip" style="--tone: ${getTone(product)}">${product.name}</span>
              <button class="details-button" type="button" data-product="${product.id}">View</button>
            </div>
            <button class="cart-add-button" type="button" data-add-cart="${product.id}" ${isSoldOut ? "disabled" : ""}>
              ${isSoldOut ? "Sold out" : cartQty > 0 ? `Add more (${cartQty})` : "Add to cart"}
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
    state.visibleCount = productBatchSize;
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

  function cartOrderText() {
    const items = getCartProducts();
    if (!items.length) return "Halo Oleena, saya ingin tanya katalog 2026.";
    return [
      "Halo Oleena, saya ingin order:",
      ...items.map((product, index) => `${index + 1}. ${product.name} - ${product.series} x${product.qty} (${formatPrice(product.price * product.qty)})`),
      `Total estimasi: ${formatPrice(getCartTotal())}`,
      "Mohon konfirmasi stok dan ongkirnya ya."
    ].join("\n");
  }

  function cartWhatsappUrl() {
    return `https://wa.me/${whatsappNumber}?text=${encodeURIComponent(cartOrderText())}`;
  }

  function addToCart(productId) {
    const product = products.find((item) => item.id === productId);
    if (!product || product.status === "sold-out") {
      showToast("Produk ini sedang sold out");
      return;
    }
    state.cart.set(productId, (state.cart.get(productId) || 0) + 1);
    saveCart();
    renderProducts();
    renderCart();
    showToast(`${product.name} masuk keranjang`);
  }

  function updateCartQty(productId, delta) {
    const current = state.cart.get(productId) || 0;
    const next = current + delta;
    if (next <= 0) state.cart.delete(productId);
    else state.cart.set(productId, next);
    saveCart();
    renderProducts();
    renderCart();
  }

  function removeFromCart(productId) {
    state.cart.delete(productId);
    saveCart();
    renderProducts();
    renderCart();
  }

  function clearCartItems() {
    state.cart.clear();
    saveCart();
    renderProducts();
    renderCart();
    showToast("Keranjang dikosongkan");
  }

  function renderCart() {
    const items = getCartProducts();
    const itemCount = getCartItemCount();
    cartCount.textContent = itemCount;
    mobileCartSummary.textContent = `${itemCount} item - ${formatPrice(getCartTotal())}`;
    mobileCartBar.hidden = itemCount === 0;
    openCart.setAttribute("aria-label", `Buka keranjang, ${itemCount} item`);
    cartEmpty.hidden = items.length > 0;
    cartItems.hidden = items.length === 0;
    clearCart.disabled = items.length === 0;
    cartTotal.textContent = formatPrice(getCartTotal());
    checkoutCart.href = cartWhatsappUrl();
    checkoutCart.setAttribute("aria-disabled", items.length === 0 ? "true" : "false");

    cartItems.innerHTML = items.map((product) => `
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

  function openProduct(product) {
    dialogContent.innerHTML = `
      <img class="dialog-image" src="${product.image}" alt="${product.name} - ${product.series}">
      <section class="dialog-details">
        <p class="eyebrow">${product.series}</p>
        <h2>${product.name}</h2>
        <div class="dialog-meta">
          <span class="pill">${formatPrice(product.price)}</span>
          <span class="pill">${product.status === "sold-out" ? "Sold out" : "Ready stock"}</span>
          <span class="pill">Halaman katalog ${product.page}</span>
        </div>
        <p class="order-note">${orderText(product).replace(/\n/g, "<br>")}</p>
        <div class="dialog-actions">
          <a class="primary-button" href="${whatsappUrl(product)}" target="_blank" rel="noreferrer">Order WhatsApp</a>
          <button class="secondary-button" type="button" data-add-cart="${product.id}" ${product.status === "sold-out" ? "disabled" : ""}>Tambah keranjang</button>
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
    state.visibleCount += productBatchSize;
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
      addToCart(addCart.dataset.addCart);
      return;
    }
    if (!detail) return;
    const product = products.find((item) => item.id === detail.dataset.product);
    if (product) openProduct(product);
  });

  dialog.addEventListener("click", (event) => {
    const addCart = event.target.closest("[data-add-cart]");
    if (addCart) {
      addToCart(addCart.dataset.addCart);
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

  window.addEventListener("keydown", (event) => {
    if (event.key === "Escape" && dialog.open) closeProduct();
  });

  renderStats();
  render();
})();

// Halaman Lacak pesanan: cari dengan kode + nomor WhatsApp, atau pilih pesanan yang pernah dibuat di perangkat ini.
(function () {
  const form = document.querySelector("#trackForm");
  const codeInput = document.querySelector("#trackCode");
  const phoneInput = document.querySelector("#trackPhone");
  const submit = document.querySelector("#trackSubmit");
  const errorBox = document.querySelector("#trackError");
  const result = document.querySelector("#trackResult");
  const deviceSection = document.querySelector("#deviceOrders");
  const deviceList = document.querySelector("#deviceOrderList");
  const toast = document.querySelector("#toast");

  const formatter = new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 });
  const formatPrice = (value) => formatter.format(value).replace(/\s/g, "");
  const dateFormatter = new Intl.DateTimeFormat("id-ID", { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" });

  const statusLabels = {
    pending_payment: "Menunggu pembayaran",
    paid: "Sudah dibayar",
    processing: "Sedang dikemas",
    shipped: "Dalam pengiriman",
    completed: "Selesai",
    cancelled: "Dibatalkan",
    expired: "Kedaluwarsa"
  };
  const steps = [
    ["pending_payment", "Pesanan dibuat", "createdAt"],
    ["paid", "Pembayaran diterima", "paidAt"],
    ["processing", "Dikemas", null],
    ["shipped", "Dikirim", "shippedAt"],
    ["completed", "Diterima", null]
  ];

  function escapeHtml(value) {
    return String(value ?? "")
      .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
  }

  // Waktu dari server berformat "YYYY-MM-DD HH:MM:SS" dalam WITA (UTC+8).
  function formatDate(value) {
    if (!value) return "";
    const date = new Date(value.replace(" ", "T") + "+08:00");
    return Number.isNaN(date.getTime()) ? value : `${dateFormatter.format(date)} WITA`;
  }

  function showToast(message) {
    toast.textContent = message;
    toast.classList.add("is-visible");
    window.clearTimeout(showToast.timer);
    showToast.timer = window.setTimeout(() => toast.classList.remove("is-visible"), 1800);
  }

  function savedTokens() {
    try {
      return JSON.parse(localStorage.getItem("oleena:orders") || "{}");
    } catch (error) {
      return {};
    }
  }

  function timeline(order) {
    if (order.status === "cancelled" || order.status === "expired") {
      return `<p class="track-stopped">${escapeHtml(statusLabels[order.status])}${order.status === "expired" ? ": batas waktu pembayaran sudah lewat." : "."}</p>`;
    }
    const current = steps.findIndex(([status]) => status === order.status);
    return `<ol class="track-steps">${steps.map(([, label, field], index) => `
      <li class="${index < current ? "is-done" : index === current ? "is-current" : ""}">
        <span class="track-dot" aria-hidden="true"></span>
        <div><strong>${label}</strong>${field && order[field] && index <= current ? `<small>${escapeHtml(formatDate(order[field]))}</small>` : ""}</div>
      </li>`).join("")}</ol>`;
  }

  function renderOrder(order) {
    const resi = order.trackingNumber ? `
      <div class="track-resi">
        <div><span>Nomor resi ${escapeHtml(order.courier)}</span><strong>${escapeHtml(order.trackingNumber)}</strong></div>
        <button class="secondary-button" type="button" data-copy="${escapeHtml(order.trackingNumber)}">Salin resi</button>
      </div>` : "";
    result.innerHTML = `
      <p class="eyebrow">Pesanan</p>
      <h2>${escapeHtml(order.code)}</h2>
      <p class="order-status">${escapeHtml(statusLabels[order.status] || order.status)}</p>
      ${resi}
      ${timeline(order)}
      <ul class="order-lines">${order.items.map((item) => `
        <li><span>${escapeHtml(item.name)} - ${escapeHtml(item.series)} x${item.qty}</span><strong>${formatPrice(item.lineTotal)}</strong></li>`).join("")}
      </ul>
      <dl class="order-summary">
        <div><dt>Tujuan</dt><dd>${escapeHtml(order.areaName)}</dd></div>
        <div><dt>Kurir</dt><dd>${escapeHtml(order.courier)}</dd></div>
        <div><dt>Ongkir</dt><dd>${formatPrice(order.shippingCost)}</dd></div>
        <div class="is-total"><dt>Total</dt><dd>${formatPrice(order.total)}</dd></div>
      </dl>
      ${order.status === "pending_payment" && savedTokens()[order.code] ? `<a class="primary-button" href="./?order=${encodeURIComponent(order.code)}">Lanjutkan pembayaran</a>` : ""}
    `;
    result.hidden = false;
    result.scrollIntoView({ behavior: "smooth", block: "start" });
  }

  async function request(url, options) {
    const response = await fetch(url, { headers: { Accept: "application/json", "Content-Type": "application/json" }, ...options });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(data.error || "Terjadi kesalahan, coba lagi.");
    return data.order;
  }

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    errorBox.hidden = true;
    const code = codeInput.value.trim().toUpperCase();
    const phone = phoneInput.value.trim();
    if (!code || !phone) {
      errorBox.textContent = "Isi kode pesanan dan nomor WhatsApp.";
      errorBox.hidden = false;
      return;
    }
    submit.disabled = true;
    submit.textContent = "Mencari...";
    try {
      renderOrder(await request("api/track.php", { method: "POST", body: JSON.stringify({ code, phone }) }));
    } catch (error) {
      result.hidden = true;
      errorBox.textContent = error.message;
      errorBox.hidden = false;
    } finally {
      submit.disabled = false;
      submit.textContent = "Lacak";
    }
  });

  document.addEventListener("click", async (event) => {
    const copy = event.target.closest("[data-copy]");
    if (copy) {
      try {
        await navigator.clipboard.writeText(copy.dataset.copy);
        showToast("Nomor resi disalin");
      } catch (error) {
        showToast(copy.dataset.copy);
      }
      return;
    }
    const pick = event.target.closest("[data-order-code]");
    if (pick) {
      const token = savedTokens()[pick.dataset.orderCode];
      try {
        renderOrder(await request(`api/order-status.php?code=${encodeURIComponent(pick.dataset.orderCode)}&token=${encodeURIComponent(token)}`));
      } catch (error) {
        showToast(error.message);
      }
    }
  });

  // Pesanan yang pernah dibuat di perangkat ini (token tersimpan saat checkout).
  async function loadDeviceOrders() {
    const entries = Object.entries(savedTokens()).reverse();
    if (!entries.length) return;
    const orders = await Promise.all(entries.map(([code, token]) =>
      request(`api/order-status.php?code=${encodeURIComponent(code)}&token=${encodeURIComponent(token)}`).catch(() => null)));
    const found = orders.filter(Boolean);
    if (!found.length) return;
    deviceList.innerHTML = found.map((order) => `
      <button class="device-order" type="button" data-order-code="${escapeHtml(order.code)}">
        <span><strong>${escapeHtml(order.code)}</strong><small>${escapeHtml(formatDate(order.createdAt))}</small></span>
        <span class="device-order-status">${escapeHtml(statusLabels[order.status] || order.status)}</span>
      </button>`).join("");
    deviceSection.hidden = false;
  }

  const prefill = new URLSearchParams(window.location.search).get("kode");
  if (prefill) codeInput.value = prefill.toUpperCase();
  loadDeviceOrders();
})();

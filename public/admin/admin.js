// Pratinjau foto sebelum diupload dan konfirmasi hapus foto (halaman produk).
(function () {
  document.querySelectorAll("input[type=file][data-preview]").forEach((input) => {
    const strip = document.getElementById(input.dataset.preview);
    const max = Number(input.dataset.max || 0);
    let urls = [];
    input.addEventListener("change", () => {
      urls.forEach((url) => URL.revokeObjectURL(url));
      urls = [];
      strip.textContent = "";
      const files = Array.from(input.files || []);
      if (max && files.length > max) {
        input.setCustomValidity(`Maksimal ${max} foto.`);
        input.reportValidity();
      } else {
        input.setCustomValidity("");
      }
      files.forEach((file, index) => {
        const url = URL.createObjectURL(file);
        urls.push(url);
        const figure = document.createElement("figure");
        const img = document.createElement("img");
        img.src = url;
        img.alt = "";
        const caption = document.createElement("figcaption");
        caption.textContent = index === 0 && input.closest(".product-form") ? "Sampul" : String(index + 1);
        figure.append(img, caption);
        strip.append(figure);
      });
    });
  });

  // Pilih kategori: kategori lama mengisi halaman katalog otomatis, "+ Kategori baru" memunculkan kolom nama.
  const seriesSelect = document.getElementById("seriesSelect");
  const seriesNewField = document.getElementById("seriesNewField");
  const catalogPage = document.getElementById("catalogPage");
  if (seriesSelect && seriesNewField) {
    const newInput = seriesNewField.querySelector("input");
    seriesSelect.addEventListener("change", () => {
      const option = seriesSelect.selectedOptions[0];
      const isNew = seriesSelect.value === "__new__";
      seriesNewField.hidden = !isNew;
      newInput.required = isNew;
      if (isNew) newInput.focus();
      if (option && option.dataset.page && catalogPage) catalogPage.value = option.dataset.page;
    });
  }

  document.addEventListener("click", (event) => {
    const button = event.target.closest("[data-confirm]");
    if (button && !window.confirm(button.dataset.confirm)) event.preventDefault();
  });
})();

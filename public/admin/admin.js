// Pratinjau foto sebelum diupload (form tambah/edit produk).
(function () {
  const input = document.getElementById("photoInput");
  const preview = document.getElementById("photoPreview");
  if (!input || !preview) return;
  input.addEventListener("change", () => {
    const file = input.files && input.files[0];
    if (!file) return;
    if (preview.dataset.objectUrl) URL.revokeObjectURL(preview.dataset.objectUrl);
    preview.dataset.objectUrl = URL.createObjectURL(file);
    preview.src = preview.dataset.objectUrl;
    preview.hidden = false;
  });
})();

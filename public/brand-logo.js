/**
 * Brand logo drop zone. The chosen file stays in the form's hidden file input
 * and is uploaded when the brand is saved; removal is the remove_logo checkbox.
 */
(function () {
    const zone = document.getElementById('logo-drop-zone');
    if (!zone) {
        return;
    }
    const input = document.getElementById('logo');
    const removeBox = document.getElementById('remove_logo');
    const preview = zone.querySelector('.logo-preview');
    const empty = zone.querySelector('.logo-empty');
    const removeBtn = zone.querySelector('.btn-remove-logo');
    const originalSrc = preview.getAttribute('data-original') || '';
    let objectUrl = null;

    function show(src) {
        if (objectUrl && src !== objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }
        preview.classList.toggle('d-none', !src);
        empty.classList.toggle('d-none', !!src);
        removeBtn.classList.toggle('d-none', !src);
        if (src) {
            preview.src = src;
        }
    }

    function choose(file) {
        if (!file || !/^image\/(jpeg|png|gif|webp|svg\+xml)$/.test(file.type)) {
            return;
        }
        const transfer = new DataTransfer();
        transfer.items.add(file);
        input.files = transfer.files;
        if (removeBox) {
            removeBox.checked = false;
        }
        objectUrl = URL.createObjectURL(file);
        show(objectUrl);
    }

    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(function (name) {
        zone.addEventListener(name, function (e) {
            e.preventDefault();
            e.stopPropagation();
        });
    });
    ['dragenter', 'dragover'].forEach(function (name) {
        zone.addEventListener(name, function () { zone.classList.add('drag-active'); });
    });
    ['dragleave', 'drop'].forEach(function (name) {
        zone.addEventListener(name, function () { zone.classList.remove('drag-active'); });
    });
    zone.addEventListener('drop', function (e) {
        choose(e.dataTransfer.files[0]);
    });
    zone.addEventListener('click', function (e) {
        if (!e.target.closest('.btn-remove-logo')) {
            input.click();
        }
    });
    input.addEventListener('change', function () {
        choose(input.files[0]);
    });
    removeBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        input.value = '';
        if (removeBox) {
            removeBox.checked = true;
        }
        show('');
    });
})();

// ── Folder Picker Modal logic ──
var fpResolve = null;

export function openFolderPicker(folders) {
    var $list = $('#fp-list');
    var $empty = $('#fp-empty');
    var $search = $('#fp-search');
    $search.val('');
    renderFpList(folders, $list, $empty);

    $search.off('input').on('input', function () {
        var q = $(this).val().toLowerCase();
        var filtered = folders.filter(function (f) { return f.toLowerCase().indexOf(q) !== -1; });
        renderFpList(filtered, $list, $empty);
    });

    $('#fp-ok').off('click').on('click', function () {
        var sel = $('input[name=fp-folder]:checked').val();
        $.modal.close();
        if (fpResolve) fpResolve(sel || null);
        fpResolve = null;
    });

    $('#fp-cancel').off('click').on('click', function () {
        $.modal.close();
        if (fpResolve) fpResolve(null);
        fpResolve = null;
    });

    $('#folderPickerModal').modal({
        modalClass: 'ywbmodal',
        fadeDuration: 250,
        fadeDelay: 0.80,
        showClose: false
    });

    return new Promise(function (resolve) { fpResolve = resolve; });
}

function renderFpList(folders, $list, $empty) {
    if (!folders.length) {
        $list.html('');
        $empty.show();
        return;
    }
    $empty.hide();
    var html = '';
    folders.forEach(function (f) {
        html += '<label><input type="radio" name="fp-folder" value="' + f + '"> ' + f + '</label>';
    });
    $list.html(html);

    $list.find('label').on('click', function () {
        $list.find('label').removeClass('fp-selected');
        $(this).addClass('fp-selected');
    });
}

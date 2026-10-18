var purifyConfig = {
    ALLOWED_TAGS: [
        // Text formatting
        'p', 'br', 'b', 'strong', 'i', 'em', 'u', 'span', 'div',
        // Headings
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        // Lists
        'ul', 'ol', 'li',
        // Links
        'a',
        // Tables (for technical specs)
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption', 'colgroup', 'col',
        // Other useful elements
        'hr', 'img', 'code', 'pre', 'blockquote', 'sup', 'sub'
    ],
    ALLOWED_ATTR: ['href', 'target', 'class', 'style', 'src', 'alt', 'width', 'height', 'colspan', 'rowspan', 'scope']
};

// Technical specs - includes table editing
$('#techspec').summernote({
    height: 300,
    toolbar: [
        ['style', ['bold', 'italic']],
        ['table', ['table']],
        ['view', ['codeview']]
    ]
});

// Other fields - minimal toolbar
$('#faq, #descr, #descr2, #descr3').summernote({
    height: 300,
    toolbar: [
        ['style', ['bold', 'italic']],
        ['view', ['codeview']]
    ]
});

// Sanitize and convert presentational tags to semantic
function sanitizeContent(content) {
    var clean = DOMPurify.sanitize(content, purifyConfig);
    clean = clean.replace(/<b>/g, '<strong>').replace(/<\/b>/g, '</strong>');
    clean = clean.replace(/<i>/g, '<em>').replace(/<\/i>/g, '</em>');
    return clean;
}

// Sanitize when leaving code view
$('.note-editor').on('click', '.note-btn-codeview', function() {
    var $editor = $(this).closest('.note-editor');
    var $textarea = $editor.prev('textarea');

    // If exiting codeview (was in codeview, now leaving)
    setTimeout(function() {
        if (!$editor.hasClass('codeview')) {
            var content = $textarea.summernote('code');
            var clean = sanitizeContent(content);
            if (content !== clean) {
                $textarea.summernote('code', clean);
            }
        }
    }, 100);
});

// Also sanitize on form submit
$('#product-form').on('submit', function() {
    $('#techspec, #faq, #descr, #descr2, #descr3').each(function() {
        var content = $(this).summernote('code');
        var clean = sanitizeContent(content);
        $(this).summernote('code', clean);
    });
});

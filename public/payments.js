// Show only the provider card matching the selected Active Checkout Provider
document.addEventListener('DOMContentLoaded', function() {
    const select = document.getElementById('payment_provider');
    if (!select) return;

    const cards = document.querySelectorAll('[data-provider-card]');

    function updateVisibleCard() {
        const selected = select.value;
        cards.forEach(function(card) {
            const isSelected = card.getAttribute('data-provider-card') === selected;
            card.classList.toggle('d-none', !isSelected);
        });
    }

    updateVisibleCard();
    select.addEventListener('change', updateVisibleCard);
});

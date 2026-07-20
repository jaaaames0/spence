<script>
function clearSearchField(inputId) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.value = '';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.focus();
}

document.querySelectorAll('[data-clear-target]').forEach(button => {
    const input = document.getElementById(button.dataset.clearTarget);
    if (!input) return;
    const update = () => button.classList.toggle('is-visible', input.value.length > 0);
    input.addEventListener('input', update);
    update();
});
</script>
</body>
</html>

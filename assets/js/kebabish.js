(() => {
  const header = document.querySelector('.ko-header');
  const button = document.querySelector('.ko-menu');
  const nav = document.querySelector('#ko-main-nav');

  if (!header || !button || !nav) return;

  const closeMenu = () => {
    header.classList.remove('is-menu-open');
    button.setAttribute('aria-expanded', 'false');
    button.setAttribute('aria-label', 'Åpne meny');
  };

  button.addEventListener('click', () => {
    const open = !header.classList.contains('is-menu-open');
    header.classList.toggle('is-menu-open', open);
    button.setAttribute('aria-expanded', open ? 'true' : 'false');
    button.setAttribute('aria-label', open ? 'Lukk meny' : 'Åpne meny');
  });

  nav.addEventListener('click', (event) => {
    if (event.target.closest('a')) closeMenu();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeMenu();
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 760) closeMenu();
  });
})();

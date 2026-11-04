(() => {
  const currentPath = window.location.pathname;
  document.querySelectorAll('[data-nav-link]').forEach((link) => {
    const href = link.getAttribute('href');
    if (href && (currentPath === href || currentPath.endsWith(href))) {
      link.setAttribute('aria-current', 'page');
    } else {
      link.removeAttribute('aria-current');
    }
  });

  document.querySelectorAll('.nav-group').forEach((group) => {
    const toggle = group.querySelector('.nav-toggle');
    if (!toggle) return;

    const close = () => group.classList.remove('open');

    toggle.addEventListener('click', (event) => {
      event.preventDefault();
      const wasOpen = group.classList.contains('open');
      document.querySelectorAll('.nav-group.open').forEach((open) => open.classList.remove('open'));
      if (!wasOpen) group.classList.add('open');
    });

    document.addEventListener('click', (event) => {
      if (!group.contains(event.target)) close();
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') close();
    });
  });
})();
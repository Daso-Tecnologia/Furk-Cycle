(() => {
  const links = Array.from(document.querySelectorAll('[data-toc-link]'));
  if (!links.length) return;

  const byId = new Map();
  links.forEach((link) => {
    const id = (link.getAttribute('href') || '').replace('#', '');
    const section = document.getElementById(id);
    if (section) byId.set(section, link);
  });

  const setActive = (section) => {
    links.forEach((link) => link.classList.toggle('is-active', byId.get(section) === link));
  };

  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver((entries) => {
      const visible = entries
        .filter((entry) => entry.isIntersecting)
        .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
      if (visible.length) setActive(visible[0].target);
    }, { rootMargin: '-10% 0px -70% 0px', threshold: 0 });
    byId.forEach((_link, section) => observer.observe(section));
  }

  links.forEach((link) => {
    link.addEventListener('click', (event) => {
      const id = (link.getAttribute('href') || '').replace('#', '');
      const section = document.getElementById(id);
      if (!section) return;
      event.preventDefault();
      section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      history.replaceState(null, '', '#' + id);
      setActive(section);
    });
  });
})();

/**
 * SafesaTech.es — Interacciones de la landing page
 */

document.addEventListener('DOMContentLoaded', () => {
  initNavbarScroll();
  initMobileMenu();
  initActiveSectionLinks();
  initScrollReveal();
  initServicePreselect();
  initContactForm();
  initLegalModal();
});

/**
 * Aplica estilo glassmorphism al navbar cuando hay desplazamiento vertical.
 */
function initNavbarScroll() {
  const navbar = document.getElementById('navbar');
  if (!navbar) return;

  const updateNavbar = () => {
    navbar.classList.toggle('is-scrolled', window.scrollY > 18);
  };

  updateNavbar();
  window.addEventListener('scroll', updateNavbar, { passive: true });
}

/**
 * Controla la apertura/cierre del menú móvil.
 */
function initMobileMenu() {
  const toggleBtn = document.getElementById('nav-toggle');
  const mobileMenu = document.getElementById('mobile-menu');
  if (!toggleBtn || !mobileMenu) return;

  const closeMenu = () => {
    toggleBtn.setAttribute('aria-expanded', 'false');
    toggleBtn.setAttribute('aria-label', 'Abrir menú de navegación');
    mobileMenu.hidden = true;
  };

  toggleBtn.addEventListener('click', () => {
    const isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
    toggleBtn.setAttribute('aria-expanded', String(!isExpanded));
    toggleBtn.setAttribute('aria-label', isExpanded ? 'Abrir menú de navegación' : 'Cerrar menú de navegación');
    mobileMenu.hidden = isExpanded;
  });

  mobileMenu.querySelectorAll('a').forEach((link) => {
    link.addEventListener('click', closeMenu);
  });

  window.addEventListener('resize', () => {
    if (window.innerWidth > 820) closeMenu();
  });
}

/**
 * Resalta el enlace activo en la barra superior según la sección visible.
 */
function initActiveSectionLinks() {
  const navLinks = Array.from(document.querySelectorAll('.nav__link'));
  const sections = navLinks
    .map((link) => document.querySelector(link.getAttribute('href')))
    .filter(Boolean);

  if (!sections.length) return;

  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const id = `#${entry.target.id}`;
        navLinks.forEach((link) => {
          link.classList.toggle('is-active', link.getAttribute('href') === id);
        });
      });
    },
    { rootMargin: '-40% 0px -50% 0px' }
  );

  sections.forEach((section) => observer.observe(section));
}

/**
 * Animación suave de aparición al entrar elementos en pantalla.
 */
function initScrollReveal() {
  const elements = document.querySelectorAll('[data-reveal]');
  if (!elements.length) return;

  if (!('IntersectionObserver' in window)) {
    elements.forEach((el) => el.classList.add('is-visible'));
    return;
  }

  const observer = new IntersectionObserver(
    (entries, obs) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const delay = Number(entry.target.dataset.revealDelay || 0);
        setTimeout(() => {
          entry.target.classList.add('is-visible');
        }, delay);
        obs.unobserve(entry.target);
      });
    },
    { threshold: 0.12, rootMargin: '0px 0px -30px 0px' }
  );

  elements.forEach((el) => observer.observe(el));
}

/**
 * Preselecciona el tipo de proyecto en el formulario al pulsar un servicio.
 */
function initServicePreselect() {
  const select = document.getElementById('tipo-proyecto');
  if (!select) return;

  document.querySelectorAll('[data-service-select]').forEach((trigger) => {
    trigger.addEventListener('click', () => {
      const value = trigger.getAttribute('data-service-select');
      if (value) {
        select.value = value;
        select.classList.remove('is-invalid');
        const err = document.getElementById('error-tipo');
        if (err) err.textContent = '';
      }
    });
  });
}

/**
 * Validación y respuesta del formulario de contacto.
 */
function initContactForm() {
  const form = document.getElementById('contact-form');
  if (!form) return;

  const nombre = document.getElementById('nombre');
  const email = document.getElementById('email');
  const tipo = document.getElementById('tipo-proyecto');
  const mensaje = document.getElementById('mensaje');
  const feedback = document.getElementById('form-feedback');

  const fields = [
    {
      input: nombre,
      errorEl: document.getElementById('error-nombre'),
      validate: (val) => (val.trim().length >= 2 ? '' : 'Por favor, indica tu nombre.'),
    },
    {
      input: email,
      errorEl: document.getElementById('error-email'),
      validate: (val) =>
        /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val.trim())
          ? ''
          : 'Introduce un correo electrónico válido.',
    },
    {
      input: tipo,
      errorEl: document.getElementById('error-tipo'),
      validate: (val) => (val ? '' : 'Selecciona el tipo de proyecto.'),
    },
    {
      input: mensaje,
      errorEl: document.getElementById('error-mensaje'),
      validate: (val) =>
        val.trim().length >= 10
          ? ''
          : 'Cuéntanos un poco más sobre tu proyecto (mínimo 10 caracteres).',
    },
  ];

  fields.forEach(({ input, errorEl, validate }) => {
    input.addEventListener('input', () => {
      if (input.classList.contains('is-invalid')) {
        const msg = validate(input.value);
        input.classList.toggle('is-invalid', Boolean(msg));
        if (errorEl) errorEl.textContent = msg;
      }
    });
  });

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    let isValid = true;

    fields.forEach(({ input, errorEl, validate }) => {
      const errorMsg = validate(input.value);
      input.classList.toggle('is-invalid', Boolean(errorMsg));
      if (errorEl) errorEl.textContent = errorMsg;
      if (errorMsg) isValid = false;
    });

    if (!isValid) {
      const firstInvalid = form.querySelector('.is-invalid');
      if (firstInvalid) firstInvalid.focus();
      return;
    }

    const clientName = nombre.value.trim();
    const projectType = tipo.value;
    const mailSubject = encodeURIComponent(`Consulta SafesaTech — ${projectType}`);
    const mailBody = encodeURIComponent(
      `Nombre: ${clientName}\nEmail: ${email.value.trim()}\nTipo de proyecto: ${projectType}\n\nMensaje:\n${mensaje.value.trim()}`
    );

    feedback.hidden = false;
    feedback.innerHTML = `
      <strong>¡Gracias, ${escapeHtml(clientName)}!</strong> Hemos registrado tu consulta sobre <em>${escapeHtml(projectType)}</em>. Te responderemos en menos de 24 horas.
      Si prefieres abrirlo directamente en tu cliente de correo, pulsa <a href="mailto:hola@safesatech.es?subject=${mailSubject}&body=${mailBody}">enviar por email</a>.
    `;

    form.reset();
  });
}

/**
 * Gestiona el modal informativo para los enlaces legales placeholder.
 */
function initLegalModal() {
  const modal = document.getElementById('legal-modal');
  const titleEl = document.getElementById('legal-modal-title');
  const bodyEl = document.getElementById('legal-modal-body');
  if (!modal || !titleEl || !bodyEl) return;

  const legalContent = {
    aviso: {
      title: 'Aviso Legal',
      body: `
        <p><strong>Titular:</strong> SafesaTech.es (Estudio Digital de Apps y Web).</p>
        <p><strong>Contacto:</strong> hola@safesatech.es — España (Servicio remoto a nivel nacional).</p>
        <p>Este texto es un marcador de posición editable. Antes de la publicación definitiva, completa aquí los datos fiscales y registrales correspondientes conforme a la Ley 34/2002 (LSSI-CE).</p>
      `,
    },
    privacidad: {
      title: 'Política de Privacidad',
      body: `
        <p>En <strong>SafesaTech.es</strong> tratamos los datos personales enviados a través del formulario de contacto únicamente para responder a tu solicitud de presupuesto o información.</p>
        <p><strong>Base legal:</strong> Consentimiento del interesado. No cedemos tus datos a terceros salvo obligación legal.</p>
        <p>Puedes ejercer tus derechos de acceso, rectificación, supresión y oposición escribiendo a <strong>hola@safesatech.es</strong>. (Texto placeholder editable conforme al RGPD y LOPDGDD).</p>
      `,
    },
    cookies: {
      title: 'Política de Cookies',
      body: `
        <p>Esta landing page utiliza únicamente recursos técnicos esenciales para su correcta visualización y funcionamiento.</p>
        <p>En caso de incorporar herramientas de analítica o seguimiento en el futuro, este apartado deberá actualizarse junto con el correspondiente banner de consentimiento. (Texto placeholder editable).</p>
      `,
    },
  };

  let lastTrigger = null;

  const closeModal = () => {
    modal.hidden = true;
    document.body.style.overflow = '';
    if (lastTrigger) lastTrigger.focus();
  };

  document.querySelectorAll('[data-legal]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const key = btn.getAttribute('data-legal');
      const data = legalContent[key];
      if (!data) return;

      lastTrigger = btn;
      titleEl.textContent = data.title;
      bodyEl.innerHTML = data.body;
      modal.hidden = false;
      document.body.style.overflow = 'hidden';
    });
  });

  modal.querySelectorAll('[data-close-modal]').forEach((el) => {
    el.addEventListener('click', closeModal);
  });

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !modal.hidden) closeModal();
  });
}

function escapeHtml(text) {
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

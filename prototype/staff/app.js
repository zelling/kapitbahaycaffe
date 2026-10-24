// Order statuses and sample tickets model the kitchen flow without a live order API.
const columns = [
  { id: 'incoming', label: 'Incoming', icon: 'inbox', action: 'Accept order', next: 'preparing' },
  { id: 'preparing', label: 'Preparing', icon: 'coffee', action: 'Mark as ready', next: 'ready' },
  { id: 'ready', label: 'Ready to serve', icon: 'bell', action: 'Complete order', next: 'completed' },
  { id: 'completed', label: 'Completed', icon: 'check-check', action: 'Completed', next: null }
];

let orders = [
  { id: 'KB-2048', status: 'incoming', name: 'Ari Mendoza', table: 'Table 04', placed: '2 min ago', items: [{ count: 1, name: 'Kapeng Bahay', note: 'Iced · Large · Oat milk' }, { count: 1, name: 'Butter Croissant', note: 'Warm, please' }] },
  { id: 'KB-2047', status: 'incoming', name: 'Lia Reyes', table: 'Table 02', placed: '4 min ago', items: [{ count: 2, name: 'Caramel Cloud', note: 'Hot · Regular · Half sweet' }] },
  { id: 'KB-2046', status: 'preparing', name: 'Noah Cruz', table: 'Table 06', placed: '7 min ago', items: [{ count: 1, name: 'Ube Latte', note: 'Iced · Large' }, { count: 1, name: 'Banana Bread', note: 'No walnuts' }] },
  { id: 'KB-2045', status: 'ready', name: 'Sam Flores', table: 'Table 01', placed: '11 min ago', items: [{ count: 1, name: 'Matcha Milk', note: 'Iced · Less sweet' }] },
  { id: 'KB-2044', status: 'completed', name: 'Maya Lim', table: 'Takeaway', placed: '18 min ago', items: [{ count: 1, name: 'Kapeng Bahay', note: 'Hot · Regular' }] }
];

let soundEnabled = true;
let toastTimer;
const board = document.querySelector('#kanban-board');

function renderDashboard() {
  board.innerHTML = columns.map((column) => {
    const columnOrders = orders.filter((order) => order.status === column.id);
    return `<section class="order-column" data-status="${column.id}" aria-label="${column.label} orders">
      <div class="column-heading"><div class="column-name"><i></i>${column.label}</div><span class="column-count">${String(columnOrders.length).padStart(2, '0')}</span></div>
      ${columnOrders.length ? columnOrders.map((order) => `<article class="order-card">
        <div class="order-card-top"><span class="order-id">${order.id}</span><span class="order-time"><i data-lucide="clock-3"></i>${order.placed}</span></div>
        <div class="customer-meta"><strong class="customer-name">${order.name}</strong><span class="table-tag">${order.table}</span></div>
        <ul class="order-items">${order.items.map((item) => `<li><span class="item-qty">${item.count}×</span><span>${item.name}</span><span class="item-customization">${item.note}</span></li>`).join('')}</ul>
        <button class="order-action" type="button" data-order-id="${order.id}" ${column.next ? '' : 'disabled'}>${column.action}${column.next ? '<i data-lucide="arrow-right"></i>' : '<i data-lucide="check"></i>'}</button>
      </article>`).join('') : `<div class="order-empty"><i data-lucide="${column.icon}"></i>Nothing here just yet.</div>`}
    </section>`;
  }).join('');
  const activeCount = orders.filter((order) => order.status !== 'completed').length;
  document.querySelector('#live-count').textContent = activeCount;
  document.querySelector('#summary-strip').innerHTML = [
    { label: 'NEW ORDERS', value: orders.filter((order) => order.status === 'incoming').length, icon: 'inbox' },
    { label: 'IN THE WORKS', value: orders.filter((order) => order.status === 'preparing').length, icon: 'coffee' },
    { label: 'READY TO SERVE', value: orders.filter((order) => order.status === 'ready').length, icon: 'bell' },
    { label: 'COMPLETED TODAY', value: orders.filter((order) => order.status === 'completed').length, icon: 'check-check' }
  ].map((item) => `<div class="summary-item"><span class="summary-icon"><i data-lucide="${item.icon}"></i></span><span class="summary-copy"><strong>${item.value}</strong><span>${item.label}</span></span></div>`).join('');
  window.lucide?.createIcons();
}

// Visual alert always runs; the optional tone is generated locally after user interaction.
function notify(message) {
  const toast = document.querySelector('#toast');
  document.querySelector('#toast-message').textContent = message;
  toast.classList.add('visible');
  window.clearTimeout(toastTimer);
  toastTimer = window.setTimeout(() => toast.classList.remove('visible'), 2600);
  if (soundEnabled && 'AudioContext' in window) {
    const context = new AudioContext();
    const oscillator = context.createOscillator();
    const gain = context.createGain();
    oscillator.connect(gain);
    gain.connect(context.destination);
    oscillator.frequency.value = 660;
    gain.gain.setValueAtTime(0.06, context.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, context.currentTime + 0.18);
    oscillator.start();
    oscillator.stop(context.currentTime + 0.18);
    oscillator.addEventListener('ended', () => context.close(), { once: true });
  }
}

document.querySelectorAll('[data-auth]').forEach((tab) => tab.addEventListener('click', () => {
  const registering = tab.dataset.auth === 'register';
  document.querySelectorAll('[data-auth]').forEach((otherTab) => {
    const selected = otherTab === tab;
    otherTab.classList.toggle('active', selected);
    otherTab.setAttribute('aria-selected', String(selected));
  });
  document.querySelector('#auth-eyebrow').textContent = registering ? 'A GOOD THING, BEING ON THE TEAM' : 'GOOD TO SEE YOU AGAIN';
  document.querySelector('#auth-title').innerHTML = registering ? 'Join the crew<span>.</span>' : 'Welcome back<span>.</span>';
  document.querySelector('#auth-subtitle').textContent = registering ? 'Set up your staff account to join the Kapetbahay team.' : 'Sign in to your crew account to pick up where you left off.';
  document.querySelector('#name-label').hidden = !registering;
  document.querySelector('#staff-name').required = registering;
  document.querySelector('#staff-password').autocomplete = registering ? 'new-password' : 'current-password';
  document.querySelector('#submit-label').textContent = registering ? 'Create account' : 'Sign in';
  document.querySelector('#form-error').textContent = '';
}));

document.querySelector('#auth-form').addEventListener('submit', (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const values = new FormData(form);
  const registering = document.querySelector('[data-auth="register"]').classList.contains('active');
  const name = String(values.get('name') || '').trim();
  const email = String(values.get('email') || '').trim();
  const password = String(values.get('password') || '');
  const error = document.querySelector('#form-error');
  error.textContent = '';
  if (registering && name.length < 2) error.textContent = 'Please enter your name so the team knows who’s on shift.';
  else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) error.textContent = 'That email address doesn’t look quite right yet.';
  else if (password.length < 8) error.textContent = 'Your password needs at least 8 characters.';
  if (error.textContent) return;
  document.querySelector('#profile-name').textContent = name || email.split('@')[0];
  document.querySelector('.profile-avatar').textContent = (name || email).trim().charAt(0).toUpperCase();
  document.querySelector('#auth-screen').hidden = true;
  document.querySelector('#dashboard').hidden = false;
  document.querySelector('#today-label').textContent = new Intl.DateTimeFormat('en-PH', { weekday: 'short', month: 'short', day: 'numeric' }).format(new Date());
  renderDashboard();
});

board.addEventListener('click', (event) => {
  const button = event.target.closest('[data-order-id]');
  if (!button) return;
  const order = orders.find((item) => item.id === button.dataset.orderId);
  const currentColumn = columns.find((column) => column.id === order?.status);
  if (!order || !currentColumn?.next) return;
  order.status = currentColumn.next;
  renderDashboard();
  notify(`${order.id} moved to ${columns.find((column) => column.id === order.status).label.toLowerCase()}.`);
});

document.querySelector('#simulate-order').addEventListener('click', () => {
  const number = 2049 + orders.filter((order) => order.id.startsWith('KB-')).length - 4;
  const names = ['Jamie Dela Cruz', 'Bea Navarro', 'Luis Garcia', 'Alex Rivera'];
  const customerName = names[Math.floor(Math.random() * names.length)];
  const orderId = `KB-${number}`;
  orders.unshift({ id: orderId, status: 'incoming', name: customerName, table: `Table 0${Math.floor(Math.random() * 6) + 1}`, placed: 'Just now', items: [{ count: 1, name: 'Kapeng Bahay', note: 'Iced · Regular · Oat milk' }] });
  renderDashboard();
  notify(`A new order from ${customerName} just came in.`);
});

document.querySelector('#notification-toggle').addEventListener('click', (event) => {
  soundEnabled = !soundEnabled;
  event.currentTarget.classList.toggle('muted', !soundEnabled);
  event.currentTarget.setAttribute('aria-pressed', String(soundEnabled));
  event.currentTarget.setAttribute('aria-label', soundEnabled ? 'Mute sound notifications' : 'Enable sound notifications');
  notify(soundEnabled ? 'Sound notifications are on.' : 'Sound notifications are muted.');
});

document.querySelector('#shift-status').addEventListener('change', (event) => {
  const isOnShift = event.target.value === 'On shift';
  document.querySelector('.service-indicator').innerHTML = `<i></i> ${isOnShift ? 'SERVICE IN PROGRESS' : 'SHIFT PAUSED'}`;
  document.querySelector('.profile-copy span').innerHTML = `<i></i> ${event.target.value}`;
});

document.querySelector('#logout').addEventListener('click', () => {
  document.querySelector('#dashboard').hidden = true;
  document.querySelector('#auth-screen').hidden = false;
  document.querySelector('#auth-form').reset();
  document.querySelector('#form-error').textContent = '';
});

window.lucide?.createIcons();
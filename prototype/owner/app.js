// Starter menu data for this frontend prototype; a live app would fetch this from an API.
const products = [
  { id: 'kapeng-bahay', name: 'Kapeng Bahay', category: 'Coffee', tag: 'House favorite', price: 145, description: 'Test', image: 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?auto=format&fit=crop&w=900&q=80' },
  { id: 'caramel-cloud', name: 'Caramel Cloud', category: 'Coffee', tag: 'A little treat', price: 165, description: 'Test', image: 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?auto=format&fit=crop&w=900&q=80' },
  { id: 'ube-latte', name: 'Ube Latte', category: 'Non-Coffee', tag: 'Local love', price: 170, description: 'Test', image: 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?auto=format&fit=crop&w=900&q=80' },
  { id: 'matcha-milk', name: 'Matcha Milk', category: 'Non-Coffee', tag: 'Slow sipper', price: 160, description: 'Test', image: 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?auto=format&fit=crop&w=900&q=80' },
  { id: 'butter-croissant', name: 'Butter Croissant', category: 'Pastries', tag: 'Baked this morning', price: 95, description: 'Test', image: 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?auto=format&fit=crop&w=900&q=80' },
  { id: 'banana-bread', name: 'Banana Bread', category: 'Snacks', tag: 'Comfort food', price: 85, description: 'Test', image: 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?auto=format&fit=crop&w=900&q=80' }
];

const cart = [];
const peso = new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' });
const productGrid = document.querySelector('#product-grid');
const cartDrawer = document.querySelector('#cart-drawer');
const productDialog = document.querySelector('#product-dialog');
const successDialog = document.querySelector('#success-dialog');
const scrim = document.querySelector('#scrim');
let selectedProduct = null;
let detailQuantity = 1;

function renderProducts(category = 'All') {
  const visibleProducts = category === 'All' ? products : products.filter((product) => product.category === category);
  productGrid.innerHTML = visibleProducts.map((product) => `
    <article class="product-card">
      <div class="product-photo" style="background-image:url('${product.image}')" role="img" aria-label="${product.name}">
        <span class="product-tag">${product.tag}</span>
      </div>
      <div class="product-body">
        <div class="product-title-row"><h3>${product.name}</h3><span class="product-price">${peso.format(product.price)}</span></div>
        <p>${product.description}</p>
        <div class="product-bottom"><small>${product.category}</small><button class="add-button" type="button" data-product="${product.id}" aria-label="Customize ${product.name}">Make it yours <i data-lucide="plus"></i></button></div>
      </div>
    </article>`).join('');
  window.lucide?.createIcons();
}

function syncOverlay() {
  const hasOverlay = cartDrawer.classList.contains('open') || productDialog.classList.contains('open') || successDialog.classList.contains('open');
  scrim.hidden = !hasOverlay;
  document.body.classList.toggle('dialog-open', hasOverlay);
}

function openProduct(product) {
  selectedProduct = product;
  detailQuantity = 1;
  document.querySelector('#product-name').textContent = product.name;
  document.querySelector('#dialog-category').textContent = product.category;
  document.querySelector('#product-description').textContent = product.description;
  document.querySelector('#dialog-image').style.backgroundImage = `url('${product.image}')`;
  const isDrink = ['Coffee', 'Non-Coffee'].includes(product.category);
  document.querySelector('#temperature-options').hidden = !isDrink;
  document.querySelectorAll('.drink-option').forEach((option) => { option.hidden = !isDrink; });
  document.querySelector('#detail-quantity').textContent = detailQuantity;
  document.querySelector('#customize-form').reset();
  updateDetailPrice();
  productDialog.classList.add('open');
  productDialog.setAttribute('aria-hidden', 'false');
  syncOverlay();
}

function updateDetailPrice() {
  if (!selectedProduct) return;
  const sizeAdd = document.querySelector('input[name="size"]:checked')?.value === 'Large' ? 35 : 0;
  const oatAdd = document.querySelector('input[name="oat"]')?.checked ? 25 : 0;
  document.querySelector('#detail-price').textContent = peso.format((selectedProduct.price + sizeAdd + oatAdd) * detailQuantity);
}

// Recalculate the bag count and 8% service tax whenever cart contents change.
function renderCart() {
  const count = cart.reduce((sum, item) => sum + item.quantity, 0);
  document.querySelector('#cart-count').textContent = count;
  document.querySelector('#empty-cart').hidden = count > 0;
  document.querySelector('#checkout-area').hidden = count === 0;
  document.querySelector('#cart-items').innerHTML = cart.map((item, index) => `
    <article class="cart-line">
      <div><h3>${item.name}</h3><p>${[item.temperature, item.size, item.sweetness, item.oat ? 'Oat milk' : ''].filter(Boolean).join(' · ')}</p><div class="cart-line-controls"><button type="button" data-cart-action="decrease" data-index="${index}" aria-label="Decrease ${item.name}">−</button><span>${item.quantity}</span><button type="button" data-cart-action="increase" data-index="${index}" aria-label="Increase ${item.name}">+</button><button class="remove-line" type="button" data-cart-action="remove" data-index="${index}" aria-label="Remove ${item.name}"><i data-lucide="trash-2"></i></button></div></div>
      <span class="cart-line-price">${peso.format(item.unitPrice * item.quantity)}</span>
    </article>`).join('');
  const subtotal = cart.reduce((sum, item) => sum + item.unitPrice * item.quantity, 0);
  document.querySelector('#subtotal').textContent = peso.format(subtotal);
  document.querySelector('#tax').textContent = peso.format(subtotal * 0.08);
  document.querySelector('#total').textContent = peso.format(subtotal * 1.08);
  window.lucide?.createIcons();
}

document.querySelector('#categories').addEventListener('click', (event) => {
  const button = event.target.closest('[data-category]');
  if (!button) return;
  document.querySelectorAll('.category-pill').forEach((pill) => {
    const isActive = pill === button;
    pill.classList.toggle('active', isActive);
    pill.setAttribute('aria-pressed', String(isActive));
  });
  renderProducts(button.dataset.category);
});

productGrid.addEventListener('click', (event) => {
  const button = event.target.closest('[data-product]');
  if (button) openProduct(products.find((product) => product.id === button.dataset.product));
});

document.querySelector('#customize-form').addEventListener('change', updateDetailPrice);
document.querySelector('#customize-form').addEventListener('click', (event) => {
  const button = event.target.closest('[data-detail-qty]');
  if (!button) return;
  detailQuantity = Math.max(1, detailQuantity + Number(button.dataset.detailQty));
  document.querySelector('#detail-quantity').textContent = detailQuantity;
  updateDetailPrice();
});

document.querySelector('#customize-form').addEventListener('submit', (event) => {
  event.preventDefault();
  if (!selectedProduct) return;
  const form = new FormData(event.currentTarget);
  const isDrink = ['Coffee', 'Non-Coffee'].includes(selectedProduct.category);
  const options = isDrink ? {
    temperature: form.get('temperature'),
    size: form.get('size'),
    sweetness: form.get('sweetness'),
    oat: form.has('oat')
  } : {};
  const unitPrice = selectedProduct.price + (options.size === 'Large' ? 35 : 0) + (options.oat ? 25 : 0);
  const existing = cart.find((item) => item.id === selectedProduct.id && JSON.stringify(item.options) === JSON.stringify(options));
  if (existing) existing.quantity += detailQuantity;
  else cart.push({ ...selectedProduct, options, ...options, unitPrice, quantity: detailQuantity });
  productDialog.classList.remove('open');
  productDialog.setAttribute('aria-hidden', 'true');
  renderCart();
  syncOverlay();
});

document.querySelector('#cart-items').addEventListener('click', (event) => {
  const button = event.target.closest('[data-cart-action]');
  if (!button) return;
  const index = Number(button.dataset.index);
  if (button.dataset.cartAction === 'remove') cart.splice(index, 1);
  if (button.dataset.cartAction === 'increase') cart[index].quantity += 1;
  if (button.dataset.cartAction === 'decrease') {
    cart[index].quantity -= 1;
    if (cart[index].quantity <= 0) cart.splice(index, 1);
  }
  renderCart();
});

document.querySelector('#open-cart').addEventListener('click', () => {
  cartDrawer.classList.add('open');
  cartDrawer.setAttribute('aria-hidden', 'false');
  syncOverlay();
});

function closeOverlays() {
  cartDrawer.classList.remove('open');
  productDialog.classList.remove('open');
  successDialog.classList.remove('open');
  [cartDrawer, productDialog, successDialog].forEach((dialog) => dialog.setAttribute('aria-hidden', 'true'));
  syncOverlay();
}

document.querySelectorAll('[data-close]').forEach((button) => button.addEventListener('click', closeOverlays));
document.querySelectorAll('[data-close-product]').forEach((button) => button.addEventListener('click', closeOverlays));
scrim.addEventListener('click', closeOverlays);
document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeOverlays(); });

// Demo checkout creates a local order number; payment and order persistence need a backend.
document.querySelector('#checkout-form').addEventListener('submit', (event) => {
  event.preventDefault();
  if (!event.currentTarget.reportValidity() || cart.length === 0) return;
  const name = new FormData(event.currentTarget).get('name').trim();
  document.querySelector('#success-name').textContent = name;
  document.querySelector('#order-number').textContent = `K${Math.floor(1000 + Math.random() * 9000)}`;
  cart.length = 0;
  event.currentTarget.reset();
  cartDrawer.classList.remove('open');
  cartDrawer.setAttribute('aria-hidden', 'true');
  successDialog.classList.add('open');
  successDialog.setAttribute('aria-hidden', 'false');
  renderCart();
  syncOverlay();
});

document.querySelector('#finish-order').addEventListener('click', closeOverlays);
renderProducts();
renderCart();
window.lucide?.createIcons();
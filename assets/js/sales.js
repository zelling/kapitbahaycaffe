(() => {
    'use strict';

    const sale = new Map();
    const itemsElement = document.getElementById('sale-items');
    const emptyElement = document.getElementById('sale-empty');
    const hiddenItemsElement = document.getElementById('sale-hidden-items');
    const totalElement = document.getElementById('sale-total');
    const submitButton = document.getElementById('record-sale-button');
    const menuGrid = document.getElementById('sale-menu-grid');

    if (!itemsElement || !emptyElement || !hiddenItemsElement || !totalElement || !submitButton || !menuGrid) {
        return;
    }

    const formatPeso = (amount) => `₱${amount.toFixed(2)}`;

    const renderSale = () => {
        itemsElement.replaceChildren();
        hiddenItemsElement.replaceChildren();
        let total = 0;

        sale.forEach((item) => {
            const lineTotal = item.price * item.quantity;
            total += lineTotal;
            const line = document.createElement('div');
            line.className = 'app-sale-line';
            line.innerHTML = `<div><h4></h4><p></p></div><strong></strong><div class="app-sale-line-actions"><div class="app-sale-stepper" aria-label="Quantity"><button type="button" data-quantity-change="-1" data-item-id="${item.id}" aria-label="Decrease quantity">−</button><output>${item.quantity}</output><button type="button" data-quantity-change="1" data-item-id="${item.id}" aria-label="Increase quantity">+</button></div><button class="app-sale-remove" type="button" data-remove-item="${item.id}">Remove</button></div>`;
            line.querySelector('h4').textContent = item.name;
            line.querySelector('p').textContent = `${formatPeso(item.price)} each`;
            line.querySelector('strong').textContent = formatPeso(lineTotal);
            itemsElement.append(line);

            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = `items[${item.id}]`;
            input.value = String(item.quantity);
            hiddenItemsElement.append(input);
        });

        emptyElement.hidden = sale.size > 0;
        totalElement.textContent = formatPeso(total);
        submitButton.disabled = sale.size === 0;
    };

    menuGrid.addEventListener('click', (event) => {
        const addButton = event.target.closest('[data-add-item]');
        if (!addButton) {
            return;
        }

        const id = addButton.dataset.itemId;
        const item = sale.get(id);
        if (item) {
            item.quantity = Math.min(item.quantity + 1, 99);
        } else {
            sale.set(id, { id, name: addButton.dataset.itemName || 'Menu item', price: Number(addButton.dataset.itemPrice), quantity: 1 });
        }
        renderSale();
    });

    itemsElement.addEventListener('click', (event) => {
        const removeButton = event.target.closest('[data-remove-item]');
        if (removeButton) {
            sale.delete(removeButton.dataset.removeItem);
            renderSale();
            return;
        }

        const quantityButton = event.target.closest('[data-quantity-change]');
        if (!quantityButton) {
            return;
        }
        const item = sale.get(quantityButton.dataset.itemId);
        if (!item) {
            return;
        }
        item.quantity += Number(quantityButton.dataset.quantityChange);
        if (item.quantity < 1) {
            sale.delete(item.id);
        } else if (item.quantity > 99) {
            item.quantity = 99;
        }
        renderSale();
    });

    document.querySelectorAll('[data-category-filter]').forEach((button) => {
        button.addEventListener('click', () => {
            const categoryId = button.dataset.categoryFilter;
            document.querySelectorAll('[data-category-filter]').forEach((filterButton) => {
                const selected = filterButton === button;
                filterButton.classList.toggle('active', selected);
                filterButton.setAttribute('aria-pressed', String(selected));
            });
            menuGrid.querySelectorAll('[data-category-id]').forEach((card) => {
                card.hidden = categoryId !== 'all' && card.dataset.categoryId !== categoryId;
            });
        });
    });

    renderSale();
})();

<?php
// checkout.php — Tela 3 "Falta pouco!" (plano 004)
// Variaveis de checkout_controller::index(): $lines, $totalCents
$csrfToken = htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8');
$itemCount = 0;
foreach ($lines as $line) {
    $itemCount += (int)$line['qty'];
}
$oldUf = strtoupper(old('uf'));
?>
<div class="container py-4" style="max-width:560px">

    <a href="<?php echo $GLOBALS['cart_url']; ?>" class="d-inline-block mb-3" style="font-size:0.85rem;color:var(--text-muted);text-decoration:none;">
        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Voltar ao pedido
    </a>

    <h1 class="mb-1" style="font-size:1.5rem;">Falta pouco!</h1>
    <p class="mb-4" style="color:var(--text-muted);font-size:0.9rem;">
        Precisamos de alguns dados para enviar seu pedido.
    </p>

    <?php html_notification_print(); ?>

    <form method="post" action="<?php echo $GLOBALS['checkout_url']; ?>" x-data="checkoutForm()" @submit="onSubmit" novalidate>
        <input type="hidden" name="_csrf_token" value="<?php echo $csrfToken; ?>">

        <div class="checkout-section-label">Seus dados</div>

        <div class="mb-3">
            <label for="name" class="form-label">Nome completo</label>
            <input type="text" class="form-control" id="name" name="name" value="<?php echo old('name'); ?>"
                   x-ref="name" @input="clearError('name')" :class="{ 'is-invalid': errors.name }" required>
            <div class="checkout-field-error" x-show="errors.name" x-cloak x-text="errors.name"></div>
        </div>

        <div class="mb-3">
            <label for="mail" class="form-label">E-mail</label>
            <input type="email" class="form-control" id="mail" name="mail" value="<?php echo old('mail'); ?>"
                   x-ref="mail" @input="normalizeEmail" :class="{ 'is-invalid': errors.mail }" required>
            <div class="checkout-field-error" x-show="errors.mail" x-cloak x-text="errors.mail"></div>
            <div class="checkout-field-hint" x-show="!errors.mail">É pra lá que vai o link do seu pedido.</div>
        </div>

        <div class="mb-3">
            <label for="phone" class="form-label">WhatsApp</label>
            <input type="tel" inputmode="numeric" class="form-control" id="phone" name="phone"
                   placeholder="(00) 00000-0000" value="<?php echo old('phone'); ?>"
                   x-ref="phone" @input="maskPhone" :class="{ 'is-invalid': errors.phone }" required>
            <div class="checkout-field-error" x-show="errors.phone" x-cloak x-text="errors.phone"></div>
        </div>

        <div class="mb-3">
            <label for="cpf" class="form-label">CPF</label>
            <input type="text" inputmode="numeric" class="form-control" id="cpf" name="cpf"
                   placeholder="000.000.000-00" value="<?php echo old('cpf'); ?>"
                   x-ref="cpf" @input="maskCpf" :class="{ 'is-invalid': errors.cpf }" required>
            <div class="checkout-field-error" x-show="errors.cpf" x-cloak x-text="errors.cpf"></div>
            <div class="checkout-field-hint" x-show="!errors.cpf">Exigido pelo banco para gerar o PIX.</div>
        </div>

        <div class="checkout-section-label">Endereço de entrega</div>

        <div class="mb-3">
            <label for="zip" class="form-label">CEP</label>
            <input type="text" inputmode="numeric" class="form-control" id="zip" name="zip"
                   placeholder="00000-000" value="<?php echo old('zip'); ?>"
                   x-ref="zip" @input="maskCep" @blur="lookupCep" :class="{ 'is-invalid': errors.zip }" required>
            <div class="checkout-field-error" x-show="errors.zip" x-cloak x-text="errors.zip"></div>
            <div class="checkout-field-hint" x-show="cepLoading" x-cloak>Buscando endereço…</div>
            <div class="checkout-field-error" x-show="cepError" x-cloak x-text="cepError"></div>
            <div class="checkout-field-hint" x-show="!cepLoading && !cepError && !errors.zip">Preenche o endereço automaticamente.</div>
        </div>

        <div class="mb-3">
            <label for="street" class="form-label">Rua</label>
            <input type="text" class="form-control" id="street" name="street" value="<?php echo old('street'); ?>"
                   x-ref="street" @input="clearError('street')" :class="{ 'is-invalid': errors.street }" required>
            <div class="checkout-field-error" x-show="errors.street" x-cloak x-text="errors.street"></div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-4">
                <label for="number" class="form-label">Número</label>
                <input type="text" class="form-control" id="number" name="number" value="<?php echo old('number'); ?>"
                       x-ref="number" @input="clearError('number')" :class="{ 'is-invalid': errors.number }" required>
                <div class="checkout-field-error" x-show="errors.number" x-cloak x-text="errors.number"></div>
            </div>
            <div class="col-8">
                <label for="complement" class="form-label">Complemento</label>
                <input type="text" class="form-control" id="complement" name="complement" value="<?php echo old('complement'); ?>">
            </div>
        </div>

        <div class="mb-3">
            <label for="district" class="form-label">Bairro</label>
            <input type="text" class="form-control" id="district" name="district" value="<?php echo old('district'); ?>"
                   x-ref="district" @input="clearError('district')" :class="{ 'is-invalid': errors.district }" required>
            <div class="checkout-field-error" x-show="errors.district" x-cloak x-text="errors.district"></div>
        </div>

        <div class="row g-2 mb-3">
            <div class="col-8">
                <label for="city" class="form-label">Cidade</label>
                <input type="text" class="form-control" id="city" name="city" value="<?php echo old('city'); ?>"
                       x-ref="city" @input="clearError('city')" :class="{ 'is-invalid': errors.city }" required>
                <div class="checkout-field-error" x-show="errors.city" x-cloak x-text="errors.city"></div>
            </div>
            <div class="col-4">
                <label for="uf" class="form-label">UF</label>
                <select class="form-select" id="uf" name="uf" x-ref="uf" @change="clearError('uf')" :class="{ 'is-invalid': errors.uf }" required>
                    <option value="">--</option>
                    <?php foreach ($GLOBALS['ufbr_lists'] as $ufCode => $ufName): ?>
                        <option value="<?php echo $ufCode; ?>"<?php echo $oldUf === $ufCode ? ' selected' : ''; ?>>
                            <?php echo htmlspecialchars($ufName, ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="checkout-field-error" x-show="errors.uf" x-cloak x-text="errors.uf"></div>
            </div>
        </div>

        <?php $feePercentLabel = rtrim(rtrim(number_format($pricing['fee_percent_bps'] / 100, 2, ',', '.'), '0'), ','); ?>
        <div class="checkout-summary-bar checkout-summary-bar--breakdown">
            <div class="d-flex justify-content-between">
                <span><?php echo $itemCount; ?> <?php echo $itemCount === 1 ? 'item' : 'itens'; ?> (subtotal)</span>
                <span>R$ <?php echo number_format($pricing['subtotal_cents'] / 100, 2, ',', '.'); ?></span>
            </div>
            <div class="d-flex justify-content-between">
                <span>Taxa <?php echo $feePercentLabel; ?>%</span>
                <span>R$ <?php echo number_format($pricing['fee_percent_cents'] / 100, 2, ',', '.'); ?></span>
            </div>
            <div class="d-flex justify-content-between">
                <span>Câmbio</span>
                <span>R$ <?php echo number_format($pricing['fee_fixed_cents'] / 100, 2, ',', '.'); ?></span>
            </div>
            <?php if ($pricing['fee_infinity_cents'] > 0): ?>
                <div class="d-flex justify-content-between">
                    <span>Taxa Infinity</span>
                    <span>R$ <?php echo number_format($pricing['fee_infinity_cents'] / 100, 2, ',', '.'); ?></span>
                </div>
            <?php endif; ?>
            <div class="d-flex justify-content-between fw-bold">
                <span>Total</span>
                <span>R$ <?php echo number_format($pricing['total_cents'] / 100, 2, ',', '.'); ?></span>
            </div>
        </div>

        <button type="submit" class="btn btn-accent w-100 btn-lg" :disabled="submitting">
            <span x-show="!submitting">Pagar</span>
            <span x-show="submitting" x-cloak>Processando…</span>
        </button>

        <p class="text-center mt-3" style="font-size:0.8rem;color:var(--text-muted);">
            Você vai ver o código PIX na próxima tela.
        </p>
    </form>
</div>

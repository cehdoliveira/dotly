<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">

            <?php html_notification_print(); ?>

            <div class="text-center mb-4">
                <h4 class="fw-bold mb-1">Definir senha</h4>
                <p class="small" style="color: var(--text-muted);">Escolha uma senha para ativar sua conta</p>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="POST"
                          action="<?php echo sprintf($GLOBALS['set_password_url'], $set_password_token); ?>"
                          x-data="setPasswordController()"
                          @submit.prevent="handleSubmit($event)"
                          x-init="init()">
                        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-3">
                            <label for="password" class="form-label">Nova senha</label>
                            <input type="password"
                                   class="form-control"
                                   :class="{ 'is-invalid': errors.password }"
                                   id="password"
                                   name="password"
                                   x-model="formData.password"
                                   autocomplete="new-password"
                                   minlength="6"
                                   required>
                            <div class="invalid-feedback" x-text="errors.password"></div>
                            <div class="form-text">Mínimo 6 caracteres.</div>
                        </div>

                        <div class="mb-4">
                            <label for="password_confirm" class="form-label">Confirmar senha</label>
                            <input type="password"
                                   class="form-control"
                                   :class="{ 'is-invalid': errors.password_confirm }"
                                   id="password_confirm"
                                   name="password_confirm"
                                   x-model="formData.password_confirm"
                                   autocomplete="new-password"
                                   required>
                            <div class="invalid-feedback" x-text="errors.password_confirm"></div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100" :disabled="isSubmitting">
                            <span x-show="isSubmitting" class="spinner-border spinner-border-sm me-1" role="status"></span>
                            <span x-text="isSubmitting ? 'Salvando...' : 'Definir senha'">Definir senha</span>
                        </button>

                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-sm-10 col-md-6 col-lg-4">

            <?php html_notification_print(); ?>

            <div class="text-center mb-4">
                <h4 class="fw-bold mb-1">Painel Administrativo</h4>
                <p class="small" style="color: var(--text-muted);"><?php echo htmlspecialchars(constant("cTitle")); ?> — acesso restrito</p>
            </div>

            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <form method="POST"
                          action="<?php echo $GLOBALS['login_url']; ?>"
                          x-data="loginController()"
                          @submit.prevent="handleSubmit($event)"
                          x-init="init()">
                        <input type="hidden" name="_csrf_token" value="<?php echo htmlspecialchars($_SESSION['_csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="mb-3">
                            <label for="login" class="form-label">Login ou e-mail</label>
                            <input type="text"
                                   class="form-control"
                                   :class="{ 'is-invalid': errors.login }"
                                   id="login"
                                   name="login"
                                   x-model="formData.login"
                                   autocomplete="username"
                                   required>
                            <div class="invalid-feedback" x-text="errors.login"></div>
                        </div>

                        <div class="mb-4">
                            <label for="password" class="form-label">Senha</label>
                            <input type="password"
                                   class="form-control"
                                   :class="{ 'is-invalid': errors.password }"
                                   id="password"
                                   name="password"
                                   x-model="formData.password"
                                   autocomplete="current-password"
                                   required>
                            <div class="invalid-feedback" x-text="errors.password"></div>
                        </div>

                        <button type="submit" class="btn btn-primary w-100" :disabled="isSubmitting">
                            <span x-show="isSubmitting" class="spinner-border spinner-border-sm me-1" role="status"></span>
                            <span x-text="isSubmitting ? 'Entrando...' : 'Entrar'">Entrar</span>
                        </button>

                    </form>
                </div>
            </div>

        </div>
    </div>
</div>

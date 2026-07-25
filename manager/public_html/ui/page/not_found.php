<?php
// not_found.php — pagina de 404 do manager (plano 019). Renderizada pelo
// fallback do dispatcher em index.php DENTRO do chrome padrao (head+header
// ja incluidos antes, footer+foot depois) — mesmo padrao de qualquer pagina
// interna do manager (ex.: ui/page/customers.php), so que sem sidebar: uma
// rota que nao existe nao pertence a nenhuma secao do menu.
$homeUrl = htmlspecialchars($GLOBALS['home_url'] ?? '/', ENT_QUOTES, 'UTF-8');
?>
<div class="manager-content" style="max-width: 480px; margin: 0 auto; padding: 4rem 1rem; text-align: center;">
    <div class="page-header" style="justify-content: center;">
        <div>
            <h1><i class="bi bi-compass me-2" aria-hidden="true"></i>Página não encontrada</h1>
            <p>O endereço acessado não existe ou não está mais disponível.</p>
        </div>
    </div>

    <a href="<?php echo $homeUrl; ?>" class="btn btn-accent">
        Voltar para o início
    </a>
</div>

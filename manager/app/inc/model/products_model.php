<?php
class products_model extends DOLModel
{
    /**
     * Nome da categoria como campo de SELECT. A relacao mora em
     * `products_categories` (tabela de attach, sem FK — convencao do projeto) e o
     * nome em `categories`, mas TODA view e consumidor le `$product['category']`
     * como string. A subquery escalar preserva esse contrato com UMA query.
     *
     * Por que nao DOLModel::attach(): attach() dispara duas queries POR LINHA
     * carregada (ver DOLModel.php:381-415) — N+1 na listagem de 25 produtos do
     * manager e na home inteira do site.
     *
     * Referencia `products.idx` com o nome cru da tabela porque load_data()
     * monta "FROM products" sem alias (DOLModel.php:264).
     *
     * ORDER BY pc.idx DESC LIMIT 1: hoje a UI garante no maximo uma ligacao ativa
     * por produto; se um dia houver mais, a mais recente prevalece em vez de a
     * query quebrar.
     */
    public const CATEGORY_NAME_FIELD = " (SELECT c.name FROM categories c INNER JOIN products_categories pc ON pc.categories_id = c.idx AND pc.active = 'yes' WHERE pc.products_id = products.idx AND c.active = 'yes' ORDER BY pc.idx DESC LIMIT 1) AS category ";

    /**
     * idx da categoria ligada ao produto — e o que o <select> do formulario de
     * produto precisa pre-selecionar. Nao entra no $field default: so a tela de
     * edicao do manager usa.
     */
    public const CATEGORY_ID_FIELD = " (SELECT pc.categories_id FROM products_categories pc WHERE pc.active = 'yes' AND pc.products_id = products.idx ORDER BY pc.idx DESC LIMIT 1) AS categories_id ";

    /**
     * Condicao de WHERE para "produtos desta categoria, pelo NOME". Usa um
     * placeholder `?` — passe o nome em $params, na mesma posicao. Mantem as URLs
     * publicas existentes (`/?cat=Nome` na home, `?categoria=Nome` no manager)
     * funcionando sem traduzir nome -> idx antes da query.
     */
    public const CATEGORY_NAME_FILTER = " idx IN (SELECT pc.products_id FROM products_categories pc INNER JOIN categories c ON c.idx = pc.categories_id AND c.active = 'yes' WHERE pc.active = 'yes' AND c.name = ?) ";

    protected array $field = [" idx ", " name ", " slug ", self::CATEGORY_NAME_FIELD, " is_infinity ", " description ", " dosage ", " purity_label ", " price_unit_cents ", " box_qty ", " stock "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("products");
    }
}

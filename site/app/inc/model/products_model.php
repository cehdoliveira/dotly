<?php
class products_model extends DOLModel
{
    /**
     * Condicao de WHERE para "produtos desta categoria, pelo NOME". Usa um
     * placeholder `?` — passe o nome em $params, na mesma posicao. Mantem as URLs
     * publicas existentes (`/?cat=Nome` na home, `?categoria=Nome` no manager)
     * funcionando sem traduzir nome -> idx antes da query. Isto e um predicado
     * de filtro (uma query so), diferente de resolver o nome pra EXIBICAO —
     * essa parte usa attach(), ver attachCategoryName() abaixo.
     */
    public const CATEGORY_NAME_FILTER = " idx IN (SELECT pc.products_id FROM products_categories pc INNER JOIN categories c ON c.idx = pc.categories_id AND c.active = 'yes' WHERE pc.active = 'yes' AND c.name = ?) ";

    protected array $field = [" idx ", " name ", " slug ", " is_infinity ", " description ", " dosage ", " purity_label ", " price_unit_cents ", " box_qty ", " stock "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("products");
    }

    /**
     * Resolve a categoria de cada produto JA CARREGADO (chame depois de
     * load_data()) usando o attach() de verdade (convencao do projeto —
     * mesma familia de auth_controller::attach(["profiles"])), e normaliza o
     * resultado de volta pro contrato de sempre: `$row['category']` (string) e
     * `$row['categories_id']` (int|null). Toda view/consumidor continua lendo
     * category como string — so este metodo sabe que por baixo e uma tabela de
     * attach.
     *
     * N+1 aceito de proposito: attach() faz 2 queries por linha carregada
     * (DOLModel.php:381-415). Troca simples e deliberada — poucas categorias,
     * paginas de no maximo 25 produtos no manager.
     *
     * "Geral" como fallback: depois da migration 018 todo produto ativo tem
     * uma ligacao real, entao este fallback so cobre corrida entre o load e a
     * migration, ou dado criado direto via model num teste sem passar por
     * save_attach().
     */
    public function attachCategoryName(): void
    {
        $this->attach(["categories"], class_field: [" idx ", " name "]);

        $data = $this->get_data();
        foreach ($data as $key => $row) {
            $linked = $row["categories_attach"][0] ?? null;
            $data[$key]["category"]      = $linked["name"] ?? "Geral";
            $data[$key]["categories_id"] = $linked["idx"]  ?? null;
        }
        $this->set_data($data);
    }
}

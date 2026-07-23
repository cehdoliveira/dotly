<?php
class orders_model extends DOLModel
{
    protected array $field = [" idx ", " token ", " status ", " customer_name ", " customer_mail ", " customer_phone ", " customer_cpf ", " ship_zip ", " ship_street ", " ship_number ", " ship_complement ", " ship_district ", " ship_city ", " ship_uf ", " subtotal_cents ", " fee_percent_cents ", " fee_fixed_cents ", " fee_infinity_cents ", " total_cents ", " paid_at ", " tracking_code ", " shipped_at ", " expires_at "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("orders");
    }
}

<?php
class blocked_customers_model extends DOLModel
{
    protected array $field = [" idx ", " customer_mail ", " customer_cpf ", " customer_phone ", " blocked_at ", " active "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("blocked_customers");
    }
}

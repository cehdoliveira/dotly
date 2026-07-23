<?php
class pix_charges_model extends DOLModel
{
    protected array $field = [" idx ", " orders_id ", " payment_gateways_id ", " gateway_charge_id ", " transaction_nsu ", " status ", " qr_payload ", " qr_image_base64 ", " redirect_url ", " amount_cents ", " expires_at ", " paid_at "];
    protected array $filter = [" active = 'yes' "];

    function __construct()
    {
        parent::__construct("pix_charges");
    }
}

<?php

if (!defined('BASEPATH'))
    exit('No direct script access allowed');

/*
 * FusionInvoice
 * 
 * A free and open source web based invoicing system
 *
 * @package		FusionInvoice
 * @author		Jesse Terry
 * @copyright	Copyright (c) 2012 - 2013, Jesse Terry
 * @license		http://www.fusioninvoice.com/support/page/license-agreement
 * @link		http://www.fusioninvoice.com
 * 
 */

class Mdl_Invoices extends Response_Model {

    public $table               = 'fi_invoices';
    public $primary_key         = 'fi_invoices.invoice_id';
    public $date_modified_field = 'invoice_date_modified';

    public function default_select()
    {
        $this->db->select("
            SQL_CALC_FOUND_ROWS fi_invoice_custom.*,
            fi_client_custom.*,
            fi_user_custom.*,
            fi_users.user_name, 
			fi_users.user_company,
			fi_users.user_address_1,
			fi_users.user_address_2,
			fi_users.user_city,
			fi_users.user_state,
			fi_users.user_zip,
			fi_users.user_country,
			fi_users.user_phone,
			fi_users.user_fax,
			fi_users.user_mobile,
			fi_users.user_email,
			fi_users.user_web,
			fi_clients.*,
			fi_invoice_amounts.invoice_amount_id,
			IFNULL(fi_invoice_amounts.invoice_item_subtotal, '0.00') AS invoice_item_subtotal,
			IFNULL(fi_invoice_amounts.invoice_item_tax_total, '0.00') AS invoice_item_tax_total,
			IFNULL(fi_invoice_amounts.invoice_tax_total, '0.00') AS invoice_tax_total,
			IFNULL(fi_invoice_amounts.invoice_total, '0.00') AS invoice_total,
			IFNULL(fi_invoice_amounts.invoice_paid, '0.00') AS invoice_paid,
			IFNULL(fi_invoice_amounts.invoice_balance, '0.00') AS invoice_balance,
			DATEDIFF(NOW(), invoice_date_due) AS days_overdue,
			(CASE 
			WHEN ((invoice_balance > 0 OR invoice_balance IS NULL OR invoice_total = 0) AND invoice_date_due < now()) THEN 'Overdue'
			WHEN ((invoice_balance > 0 OR invoice_balance IS NULL OR invoice_total = 0) AND invoice_date_due >= now()) THEN 'Open'
			WHEN (invoice_balance = 0 and invoice_total > 0) THEN 'Closed'
			ELSE 'Unknown' END) AS invoice_status,
            (CASE (SELECT COUNT(*) FROM fi_invoices_recurring WHERE fi_invoices_recurring.invoice_id = fi_invoices.invoice_id and fi_invoices_recurring.recur_next_date <> '0000-00-00') WHEN 0 THEN 0 ELSE 1 END) AS invoice_is_recurring,
			fi_invoices.*", FALSE);
    }

    public function default_order_by()
    {
        $this->db->order_by('fi_invoices.invoice_date_created DESC');
    }

    public function default_join()
    {
        $this->db->join('fi_clients', 'fi_clients.client_id = fi_invoices.client_id');
        $this->db->join('fi_users', 'fi_users.user_id = fi_invoices.user_id');
        $this->db->join('fi_invoice_amounts', 'fi_invoice_amounts.invoice_id = fi_invoices.invoice_id', 'left');
        $this->db->join('fi_client_custom', 'fi_client_custom.client_id = fi_clients.client_id', 'left');
        $this->db->join('fi_user_custom', 'fi_user_custom.user_id = fi_users.user_id', 'left');
        $this->db->join('fi_invoice_custom', 'fi_invoice_custom.invoice_id = fi_invoices.invoice_id', 'left');
    }

    public function validation_rules()
    {
        return array(
            'client_name'          => array(
                'field' => 'client_name',
                'label' => lang('client'),
                'rules' => 'required'
            ),
            'invoice_date_created' => array(
                'field' => 'invoice_date_created',
                'label' => lang('invoice_date'),
                'rules' => 'required'
            ),
            'invoice_group_id'     => array(
                'field' => 'invoice_group_id',
                'label' => lang('invoice_group'),
                'rules' => 'required'
            ),
            'user_id'              => array(
                'field' => 'user_id',
                'label' => lang('user'),
                'rule'  => 'required'
            )
        );
    }

    public function validation_rules_save_invoice()
    {
        return array(
            'invoice_number'       => array(
                'field' => 'invoice_number',
                'label' => lang('invoice_number'),
                'rules' => 'required|is_unique[fi_invoices.invoice_number' . (($this->id) ? '.invoice_id.' . $this->id : '') . ']'
            ),
            'invoice_date_created' => array(
                'field' => 'invoice_date_created',
                'label' => lang('date'),
                'rules' => 'required'
            ),
            'invoice_date_due'     => array(
                'field' => 'invoice_date_due',
                'label' => lang('due_date'),
                'rules' => 'required'
            )
        );
    }

    public function create($db_array = NULL)
    {
        $invoice_id = parent::save(NULL, $db_array);

        // Create an invoice amount record
        $db_array = array(
            'invoice_id' => $invoice_id
        );

        $this->db->insert('fi_invoice_amounts', $db_array);

        // Create the default invoice tax record if applicable
        if ($this->mdl_settings->setting('default_invoice_tax_rate'))
        {
            $db_array = array(
                'invoice_id'              => $invoice_id,
                'tax_rate_id'             => $this->mdl_settings->setting('default_invoice_tax_rate'),
                'include_item_tax'        => $this->mdl_settings->setting('default_include_item_tax'),
                'invoice_tax_rate_amount' => 0
            );

            $this->db->insert('fi_invoice_tax_rates', $db_array);
        }

        return $invoice_id;
    }

    public function get_url_key()
    {
        $this->load->helper('string');
        return random_string('unique');
    }

    /**
     * Copies invoice items, tax rates, etc from source to target
     * @param int $source_id
     * @param int $target_id
     */
    public function copy_invoice($source_id, $target_id)
    {
        $this->load->model('invoices/mdl_items');
        
        $invoice_items = $this->mdl_items->where('invoice_id', $source_id)->get()->result();

        foreach ($invoice_items as $invoice_item)
        {
            $db_array = array(
                'invoice_id'       => $target_id,
                'item_tax_rate_id' => $invoice_item->item_tax_rate_id,
                'item_name'        => $invoice_item->item_name,
                'item_description' => $invoice_item->item_description,
                'item_quantity'    => $invoice_item->item_quantity,
                'item_price'       => $invoice_item->item_price,
                'item_order'       => $invoice_item->item_order
            );

            $this->mdl_items->save($target_id, NULL, $db_array);
        }

        $invoice_tax_rates = $this->mdl_invoice_tax_rates->where('invoice_id', $source_id)->get()->result();

        foreach ($invoice_tax_rates as $invoice_tax_rate)
        {
            $db_array = array(
                'invoice_id'              => $target_id,
                'tax_rate_id'             => $invoice_tax_rate->tax_rate_id,
                'include_item_tax'        => $invoice_tax_rate->include_item_tax,
                'invoice_tax_rate_amount' => $invoice_tax_rate->invoice_tax_rate_amount
            );

            $this->mdl_invoice_tax_rates->save($target_id, NULL, $db_array);
        }
    }

    public function db_array()
    {
        $db_array = parent::db_array();

        // Get the client id for the submitted invoice
        $this->load->model('clients/mdl_clients');
        $db_array['client_id'] = $this->mdl_clients->client_lookup($db_array['client_name']);
        unset($db_array['client_name']);

        $db_array['invoice_date_created'] = date_to_mysql($db_array['invoice_date_created']);
        $db_array['invoice_date_due']     = $this->get_date_due($db_array['invoice_date_created']);
        $db_array['invoice_number']       = $this->get_invoice_number($db_array['invoice_group_id']);
        $db_array['invoice_terms']        = $this->mdl_settings->setting('default_invoice_terms');

        // Generate the unique url key
        $db_array['invoice_url_key'] = $this->get_url_key();

        return $db_array;
    }

    public function get_invoice_number($invoice_group_id)
    {
        $this->load->model('invoice_groups/mdl_invoice_groups');
        return $this->mdl_invoice_groups->generate_invoice_number($invoice_group_id);
    }

    public function get_date_due($invoice_date_created)
    {
        $invoice_date_due = new DateTime($invoice_date_created);
        $invoice_date_due->add(new DateInterval('P' . $this->mdl_settings->setting('invoices_due_after') . 'D'));
        return $invoice_date_due->format('Y-m-d');
    }

    public function delete($invoice_id)
    {
        parent::delete($invoice_id);

        $this->load->helper('orphan');
        delete_orphans();
    }

    public function is_open()
    {
        // Optional function to retrieve invoices with balance
        $this->filter_having('invoice_status', 'Open');
        return $this;
    }

    public function is_closed()
    {
        // Optional function to retrieve invoices without balance
        $this->filter_having('invoice_status', 'Closed');
        return $this;
    }

    public function is_overdue()
    {
        // Optional function to retrieve overdue invoices
        $this->filter_having('invoice_status', 'Overdue');
        return $this;
    }

    public function by_client($client_id)
    {
        $this->filter_where('fi_invoices.client_id', $client_id);
        return $this;
    }

}

?>
<?php

class Cron extends Base_Controller {

    public function recur($cron_key = NULL)
    {
        if ($cron_key == $this->mdl_settings->setting('cron_key'))
        {
            $this->load->model('invoices/mdl_invoices_recurring');
            $this->load->model('invoices/mdl_invoices');

            // Gather a list of recurring invoices to generate
            $invoices_recurring = $this->mdl_invoices_recurring->active()->get()->result();
            
            foreach ($invoices_recurring as $invoice_recurring)
            {
                // This is the original invoice id
                $source_id = $invoice_recurring->invoice_id;
                
                // This is the original invoice
                $invoice = $this->db->where('fi_invoices.invoice_id', $source_id)->get('fi_invoices')->row();

                // Create the new invoice
                $db_array = array(
                    'client_id'            => $invoice->client_id,
                    'invoice_date_created' => $invoice_recurring->recur_next_date,
                    'invoice_date_due'     => $this->mdl_invoices->get_date_due($invoice_recurring->recur_next_date),
                    'invoice_group_id'     => $invoice->invoice_group_id,
                    'user_id'              => $invoice->user_id,
                    'invoice_number'       => $this->mdl_invoices->get_invoice_number($invoice->invoice_group_id),
                    'invoice_url_key'      => $this->mdl_invoices->get_url_key(),
                    'invoice_terms'        => $invoice->invoice_terms
                );

                // This is the new invoice id
                $target_id = $this->mdl_invoices->create($db_array);

                // Copy the original invoice to the new invoice
                $this->mdl_invoices->copy_invoice($source_id, $target_id);
                
                // Update the next recur date for the recurring invoice
                $this->mdl_invoices_recurring->set_next_recur_date($invoice_recurring->invoice_recurring_id);
            }
        }
    }

}

?>
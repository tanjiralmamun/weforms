<?php

/**
 * WP SI Integration
 */
class WeForms_Integration_SI extends WeForms_Abstract_Integration
{

    /**
     * Initialize the plugin
     */
    public function __construct()
    {
        $this->id = 'sprout-invoices';
        $this->title = __( 'Sprout Invoices', 'weforms' );
        $this->icon = WEFORMS_ASSET_URI . '/images/icon-sprout-invoices.png';

        $this->settings_fields = [
            'enabled' => false,
            'group' => [],
            'stage' => 'subscriber',
            'fields' => [
                'subject' => '',
                'client_name' => '',
                'email' => '',
                'first_name' => '',
                'last_name' => '',
                'address' => '',
                'notes' => '',
                'duedate' => '',
                'number' => '',
                'vat' => '',
                'line_items' => ''
            ],
        ];

        add_action( 'weforms_entry_submission', [$this, 'create_doc'], 10, 2 );
        add_filter( 'weforms_entry_submission_response', [$this, 'modify_response_data_redirect'] );
    }


    public function modify_response_data_redirect($response_data)
    {
        $si_redirect = weforms_get_entry_meta( $response_data['entry_id'], 'wpuf_si_redirect_url', true );
        if ('' !== $si_redirect) {
            $response_data['show_message'] = 0;
            $response_data['redirect_to'] = $si_redirect;
        }
        return $response_data;
    }

    /**
     * Subscribe a user when a form is submitted
     *
     * @param int $entry_id
     * @param int $form_id
     *
     * @return void
     */
    public function create_doc($entry_id, $form_id)
    {
        if (!$this->has_dependency()) {
            return;
        }

        $integration = weforms_is_integration_active( $form_id, $this->id );

        if (false === $integration) {
            return;
        }

        $form_data = weforms_get_entry_data( $entry_id );
        if (false === $form_data) {
            return;
        }
        $address = self::get_array( $integration->fields->address, $entry_id );
        if (is_array( $address )) {
            $full_address = array(
                'street' => isset( $address['street_address'] ) ? $address['street_address'] . ' ' . $address['street_address2'] : '',
                'city' => isset( $address['city_name'] ) ? $address['city_name'] : '',
                'zone' => isset( $address['state'] ) ? $address['state'] : '',
                'postal_code' => isset( $address['zip'] ) ? $address['zip'] : '',
                'country' => isset( $address['country_select'] ) ? $address['country_select'] : '',
            );
        }

        /*$line_items = self::get_array( $integration->fields->line_items, $entry_id );
        if (is_array($line_items)) {
            foreach ($line_items as $rate => $value) {
                $li[] = array(
                    'desc' => $value,
                    'rate' => $rate,
                    'total' => $rate,
                    'qty' => 1,
                );
            }
        }*/

        //Setting up array to send user info to WordPress
        $submission = array(
            'subject' => self::maybe_name( $integration->fields->subject, $entry_id ),
            //'line_items' => ! empty( $li ) ? $li : array() ,
            'full_address' => !empty( $full_address ) ? $full_address : array(),
            'client_name' => self::maybe_name( $integration->fields->client_name, $entry_id ),
            'email' => WeForms_Notification::replace_field_tags( $integration->fields->email, $entry_id ),
            'first_name' => self::maybe_name( $integration->fields->first_name, $entry_id ),
            'last_name' => self::maybe_name( $integration->fields->last_name, $entry_id ),
            'notes' => WeForms_Notification::replace_field_tags( $integration->fields->notes, $entry_id ),
            'duedate' => WeForms_Notification::replace_field_tags( $integration->fields->duedate, $entry_id ),
            'number' => WeForms_Notification::replace_field_tags( $integration->fields->number, $entry_id ),
            'vat' => WeForms_Notification::replace_field_tags( $integration->fields->vat, $entry_id ),
            'entry_note' => self::get_fields_table( $form_id, $entry_id ),
            'edit_url' => admin_url( sprintf( 'admin.php?page=weforms#/form/%s/entries/%s', $form_id, $entry_id ) ),
        );

        $doc_id = 0;
        $doctype = $integration->doctype;
        $create_user_and_client = $integration->create_user_and_client;
        switch ($doctype) {
            case 'invoice':
                $doc_id = $this->create_invoice( $submission, $form_data['data'], $form_id );
                if ($create_user_and_client) {
                    $this->create_client( $submission, $form_data['data'], $doc_id, $form_id );
                }
                break;
            case 'estimate':
                $doc_id = $this->create_estimate( $submission, $form_data['data'], $form_id );
                if ($create_user_and_client) {
                    $this->create_client( $submission, $form_data['data'], $doc_id, $form_id );
                }
                break;
            case 'client':
                $this->create_client( $submission, $form_data['data'], 0, $form_id );
                break;
            default:
                // nada
                break;
        }

        // REDIRECT
        $redirect = $integration->redirect;
        if ($redirect && $doc_id) {
            $doc = si_get_doc_object( $doc_id );
            $doc->set_pending();
            $url = wp_get_referer();
            if (get_post_type( $doc_id ) == SI_Invoice::POST_TYPE) {
                $url = get_permalink( $doc_id );
            } elseif (get_post_type( $doc_id ) == SI_Estimate::POST_TYPE) {
                $url = get_permalink( $doc_id );
            }
            weforms_add_entry_meta( $entry_id, 'wpuf_si_redirect_url', $url );
        }


    }

    /**
     * Check if the dependency met
     *
     * @return bool
     */
    public function has_dependency()
    {
        return function_exists( 'sprout_invoices_load' );
    }

    private static function get_array($text, $entry_id)
    {
        $pattern = '/{field:(\w*)}/';

        preg_match_all( $pattern, $text, $matches );

        // bail out if nothing found to be replaced
        if (!$matches) {
            return $text;
        }

        // returning the first address, can't really deal with more.
        foreach ($matches[1] as $index => $meta_key) {
            return weforms_get_entry_meta( $entry_id, $meta_key, true );
        }
    }

    public static function maybe_name($text, $entry_id)
    {
        $pattern = '/{name-(full|first|middle|last):(\w*)}/';

        preg_match_all( $pattern, $text, $matches );

        // bail out if nothing found to be replaced
        if (!$matches[0]) {
            return WeForms_Notification::replace_field_tags( $text, $entry_id );
        }

        list( $search, $fields, $meta_key ) = $matches;

        $meta_value = weforms_get_entry_meta( $entry_id, $meta_key[0], true );
        $replace = explode( WeForms::$field_separator, $meta_value );

        foreach ($search as $index => $search_key) {
            if ('first' == $fields[$index]) {
                $text = str_replace( $search_key, $replace[0], $text );
            } elseif ('middle' == $fields[$index]) {
                $text = str_replace( $search_key, $replace[1], $text );
            } elseif ('last' == $fields[$index]) {
                $text = str_replace( $search_key, $replace[2], $text );
            } else {
                $text = str_replace( $search_key, implode( ' ', $replace ), $text );
            }
        }

        return $text;
    }

    protected function create_invoice($submission = array(), $entry = array(), $form_id = 0)
    {

        $invoice_args = array(
            'subject' => sprintf( apply_filters( 'si_form_submission_title_format', '%1$s (%2$s)', $submission ), $submission['subject'], $submission['client_name'] ),
            'fields' => $submission,
            'form' => $entry,
            'history_link' => '<a href="' . $submission['edit_url'] . '">#' . $form_id . '</a>',
        );

        do_action( 'si_doc_generation_start' );

        /**
         * Creates the invoice from the arguments
         */
        $invoice_id = SI_Invoice::create_invoice( $invoice_args );
        $invoice = SI_Invoice::get_instance( $invoice_id );
        $invoice->set_line_items( $submission['line_items'] );
        $invoice->set_calculated_total();

        // notes
        if (isset( $submission['notes'] ) && '' !== $submission['notes']) {
            SI_Internal_Records::new_record( $submission['notes'], SI_Controller::PRIVATE_NOTES_TYPE, $invoice_id, '', 0, false );
        }

        SI_Internal_Records::new_record( $submission['entry_note'], SI_Controller::PRIVATE_NOTES_TYPE, $invoice_id, '', 0, false );

        if (isset( $submission['number'] )) {
            $invoice->set_invoice_id( $submission['number'] );
        }

        if (isset( $submission['duedate'] )) {
            $invoice->set_due_date( $submission['duedate'] );
        }

        // Finally associate the doc with the form submission
        add_post_meta( $invoice_id, 'wef_form_id', $form_id );

        $history_link = sprintf( '<a href="%s">#%s</a>', $submission['edit_url'], $form_id );

        do_action( 'si_new_record',
            sprintf( __( 'Invoice Submitted: Form %s.', 'sprout-invoices' ), $history_link ),
            'invoice_submission',
            $invoice_id,
            sprintf( __( 'Invoice Submitted: Form %s.', 'sprout-invoices' ), $history_link ),
            0,
            false );

        do_action( 'si_invoice_submitted_from_adv_form', $invoice, $invoice_args, $submission, $entry );

        return $invoice_id;

    }

    protected function create_client($submission = array(), $entry = array(), $doc_id = 0, $form_id = 0)
    {

        $email = $submission['email'];
        $client_name = $submission['client_name'];
        $first_name = $submission['first_name'];
        $last_name = $submission['last_name'];

        /**
         * Attempt to create a user before creating a client.
         */
        $user_id = get_current_user_id();
        if (!$user_id) {
            if ('' !== $email) {
                // check to see if the user exists by email
                $user = get_user_by( 'email', $email );
                if ($user) {
                    $user_id = $user->ID;
                }
            }
        }

        // Create a user for the submission if an email is provided.
        if (!$user_id) {
            // email is critical
            if ('' !== $email) {
                $user_args = array(
                    'user_login' => esc_attr__( $email ),
                    'display_name' => isset( $client_name ) ? esc_attr__( $client_name ) : esc_attr__( $email ),
                    'user_email' => esc_attr__( $email ),
                    'first_name' => $first_name,
                    'last_name' => $last_name,
                    'user_url' => '',
                );
                $user_id = SI_Clients::create_user( $user_args );
            }
        }

        // Make up the args in creating a client
        $args = array(
            'company_name' => $submission['client_name'],
            'website' => '',
            'address' => $submission['full_address'],
            'user_id' => $user_id,
        );
        $client_id = SI_Client::new_client( $args );
        $client = SI_Client::get_instance( $client_id );

        if (isset( $submission['vat'] )) {
            $client->save_post_meta( array('_iva' => $submission['vat']) );
            $client->save_post_meta( array('_vat' => $submission['vat']) );
        }

        if (!$doc_id) {
            return;
        }

        /**
         * After a client is created assign it to the estimate
         */
        $doc = si_get_doc_object( $doc_id );
        $doc->set_client_id( $client_id );

    }

    protected function create_estimate($submission = array(), $entry = array(), $form_id)
    {
        $estimate_args = array(
            'subject' => sprintf( apply_filters( 'si_form_submission_title_format', '%1$s (%2$s)', $submission ), $submission['subject'], $submission['client_name'] ),
            'fields' => $submission,
            'form' => $submission,
            'history_link' => $submission['edit_url'],
        );

        do_action( 'si_doc_generation_start' );

        /**
         * Creates the estimate from the arguments
         */
        $estimate_id = SI_Estimate::create_estimate( $estimate_args );
        $estimate = SI_Estimate::get_instance( $estimate_id );
        do_action( 'si_estimate_submitted_from_adv_form', $estimate, $estimate_args );

        $estimate->set_line_items( $submission['line_items'] );

        // notes
        if (isset( $submission['notes'] ) && '' !== $submission['notes']) {
            SI_Internal_Records::new_record( $submission['notes'], SI_Controller::PRIVATE_NOTES_TYPE, $estimate_id, '', 0, false );
        }
        SI_Internal_Records::new_record( $submission['entry_note'], SI_Controller::PRIVATE_NOTES_TYPE, $estimate_id, '', 0, false );

        if (isset( $submission['number'] )) {
            $estimate->set_estimate_id( $submission['number'] );
        }

        if (isset( $submission['duedate'] )) {
            $estimate->set_expiration_date( $submission['duedate'] );
        }

        // Finally associate the doc with the form submission
        add_post_meta( $estimate_id, 'wef_form_id', $form_id );

        $history_link = '<a href="' . $submission['edit_url'] . '">#' . $form_id . '</a>';

        do_action( 'si_new_record',
            sprintf( __( 'Estimate Submitted: Form %s.', 'sprout-invoices' ), $history_link ),
            'estimate_submission',
            $estimate_id,
            sprintf( __( 'Estimate Submitted: Form %s.', 'sprout-invoices' ), $history_link ),
            0,
            false );

        return $estimate_id;
    }

    public static function get_fields_table($form_id, $entry_id)
    {

        $form = weforms()->form->get( $form_id );
        $entry = $form->entries()->get( $entry_id );
        $fields = $entry->get_fields();

        if (!$fields) {
            return '';
        }

        $table = '<table width="600" cellpadding="0" cellspacing="0">';
        $table .= '<tbody>';

        foreach ($fields as $key => $value) {
            $field_value = isset( $value['value'] ) ? $value['value'] : '';

            if (!$field_value) {
                continue; // let's skip empty fields
            }

            $table .= '<tr class="field-label">';
            $table .= '<th><strong>' . $value['label'] . '</strong></th>';
            $table .= '</tr>';
            $table .= '<tr class="field-value">';
            $table .= '<td>';

            if (in_array( $value['type'], ['multiple_select', 'checkbox_field'] )) {
                $field_value = is_array( $field_value ) ? $field_value : [];

                if ($field_value) {
                    $table .= '<ul>';

                    foreach ($field_value as $value_key) {
                        $table .= '<li>' . $value_key . '</li>';
                    }
                    $table .= '</ul>';
                } else {
                    $table .= '&mdash;';
                }
            } else {
                $table .= $field_value;
            }

            $table .= '</td>';
            $table .= '</tr>';
        }

        $table .= '</tbody>';
        $table .= '</table>';

        return $table;
    }
}

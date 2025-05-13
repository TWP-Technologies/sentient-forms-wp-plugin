<?php
/**
 * Form builder adapter interface
 *
 * @package Sentient_Forms
 */

// Exit if accessed directly
if ( !defined( 'ABSPATH' ) )
{
    exit;
}

/**
 * Interface Sentient_Forms_Adapter_Interface
 * Defines the contract for all form provider adapters.
 */
interface Sentient_Forms_Adapter_Interface
{

    /**
     * Get the unique identifier for the adapter.
     * This ID is used internally to reference the adapter.
     * Example: 'gravity_forms', 'wpforms'.
     *
     * @return string Unique adapter ID.
     */
    public function get_id(): string;

    /**
     * Get the human-readable name of the form provider.
     * This name is used in the admin UI.
     * Example: "Gravity Forms", "WPForms".
     *
     * @return string Human-readable adapter name.
     */
    public function get_name(): string;

    /**
     * Check if the corresponding form plugin is active.
     * This method is crucial for determining if the adapter can be used.
     *
     * @return bool True if the form plugin is active, false otherwise.
     */
    public function is_active(): bool;

    /**
     * Retrieve a list of forms available from the provider.
     * Each form in the returned array should be an associative array
     * containing at least 'id' and 'name' keys.
     * Example: [ ['id' => 1, 'name' => 'Contact Us Form'], ['id' => 2, 'name' => 'Quote Request'] ]
     *
     * @return array List of forms.
     */
    public function get_forms(): array;

    /**
     * Retrieve fields for a specific form.
     * This is useful for populating merge tag pickers or understanding form structure.
     * Each field should be an associative array with keys like 'id', 'label', 'type', 'merge_tag'.
     *
     * @param mixed $form_id The ID of the form.
     *
     * @return array List of form fields.
     */
    public function get_form_fields( $form_id ): array;

    /**
     * Retrieve data for a specific form entry.
     * The structure of the returned data will depend on the form provider.
     *
     * @param mixed $entry_id The ID of the entry.
     * @param mixed $form_id  Optional. The ID of the form, if needed to retrieve the entry.
     *
     * @return array|object|null Entry data, or null if not found.
     */
    public function get_entry_data( $entry_id, $form_id = null );

    /**
     * Update metadata for a specific form entry.
     * Used to store results of Sentient Forms actions (e.g., spam score, evaluation notes).
     *
     * @param mixed  $entry_id   The ID of the entry.
     * @param string $meta_key   The meta key to update.
     * @param mixed  $meta_value The new meta value.
     *
     * @return bool True on success, false on failure.
     */
    public function update_entry_meta( $entry_id, string $meta_key, $meta_value ): bool;

    /**
     * Mark a form entry as spam.
     * How this is implemented depends on the form provider's capabilities.
     *
     * @param mixed $entry_id The ID of the entry.
     *
     * @return bool True on success, false on failure.
     */
    public function mark_entry_as_spam( mixed $entry_id ): bool;

    /**
     * Reject a form submission.
     * This could involve marking the entry as rejected, trashing it, or adding a specific note.
     *
     * @param mixed  $entry_id The ID of the entry.
     * @param string $message  The reason for rejection.
     *
     * @return bool True on success, false on failure.
     */
    public function reject_submission( mixed $entry_id, string $message ): bool;

    /**
     * Add a note to a form entry.
     * Notes are often used for logging action results or manual reviews.
     *
     * @param mixed  $entry_id     The ID of the entry.
     * @param string $note_author  The author of the note (e.g., "Sentient Forms AI").
     * @param string $note_content The content of the note.
     *
     * @return bool True if the note was added successfully, false otherwise.
     */
    public function add_entry_note( mixed $entry_id, string $note_author, string $note_content ): bool;

    /**
     * Get the specific WordPress action hook name for a generic event.
     * This allows actions to hook into form provider events (like submission)
     * without needing to know the provider-specific hook names.
     *
     * @param string $event_name Generic event name (e.g., 'before_submission', 'after_submission', 'entry_created').
     *
     * @return string|null The WordPress hook name, or null if not applicable/supported for the event.
     */
    public function get_action_hook_for_event( string $event_name ): ?string;

    /**
     * Retrieve the form object/structure from the provider.
     * This can be useful for accessing detailed form settings or properties.
     * The structure of the returned object/array depends on the form provider.
     *
     * @param int $form_id The ID of the form.
     *
     * @return array|object|null The form object/array, or null if not found.
     */
    public function get_form_object( int $form_id ): object | array | null;
}
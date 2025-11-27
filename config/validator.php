<?php
/**
 * Input Validation and Sanitization
 */

class Validator {
    private $errors = [];
    
    /**
     * Sanitize a string by trimming, stripping HTML tags and enforcing max length.
     */
    public function sanitizeString(?string $input, int $maxLength = 255): string {
        if ($input === null) return '';
        
        $input = trim($input);
        $input = strip_tags($input);
        
        if ($maxLength > 0 && strlen($input) > $maxLength) {
            $input = substr($input, 0, $maxLength);
        }
        
        return $input;
    }
    
    /**
     * Sanitize numeric input.
     * Accepts comma or dot, converts to float, and ensures non-negative.
     */
    public function sanitizeNumeric(?string $input): ?float {
        if ($input === null || trim($input) === '') {
            return null;
        }
        
        // Replace comma with dot
        $input = str_replace(',', '.', trim($input));
        
        // Validate numeric
        if (!is_numeric($input)) {
            return null;
        }
        
        $value = (float)$input;
        
        // Only allow values >= 0
        return $value >= 0 ? $value : null;
    }
    
    /**
     * Sanitize a date and verify format (YYYY-MM-DD).
     */
    public function sanitizeDate(?string $input): ?string {
        if (empty($input)) return null;
        
        $date = DateTime::createFromFormat('Y-m-d', $input);
        
        if (!$date || $date->format('Y-m-d') !== $input) {
            return null;
        }
        
        return $input;
    }
    
    /**
     * Validate required fields.
     */
    public function validateRequired(string $field, $value, string $label = null): bool {
        if (empty($value)) {
            $this->errors[$field] = ($label ?? $field) . ' is required';
            return false;
        }
        return true;
    }
    
    /**
     * Optional date validator. If filled, must be valid.
     */
    public function validateDate(string $field, ?string $value, string $label = null): bool {
        if (empty($value)) return true;
        
        if ($this->sanitizeDate($value) === null) {
            $this->errors[$field] = ($label ?? $field) . ' must be a valid date (YYYY-MM-DD)';
            return false;
        }
        return true;
    }
    
    /**
     * Optional numeric validator. If filled, must be a valid positive number.
     */
    public function validateNumeric(string $field, $value, string $label = null): bool {
        if ($value === null || $value === '') return true;
        
        if ($this->sanitizeNumeric($value) === null) {
            $this->errors[$field] = ($label ?? $field) . ' must be a positive number';
            return false;
        }
        return true;
    }
    
    /**
     * Check if any validation errors exist.
     */
    public function hasErrors(): bool {
        return !empty($this->errors);
    }
    
    /**
     * Get all validation errors.
     */
    public function getErrors(): array {
        return $this->errors;
    }
    
    /**
     * Get the first validation error.
     */
    public function getFirstError(): ?string {
        return !empty($this->errors) ? reset($this->errors) : null;
    }
}

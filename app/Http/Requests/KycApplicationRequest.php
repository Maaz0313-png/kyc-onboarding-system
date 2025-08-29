<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class KycApplicationRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $rules = [
            'cnic' => [
                'required',
                'string',
                'regex:/^\d{5}-\d{7}-\d$/',
                Rule::unique('kyc_applications', 'cnic')->ignore($this->route('kycApplication')?->id)
            ],
            'full_name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                'regex:/^[a-zA-Z\s]+$/'
            ],
            'father_name' => [
                'required',
                'string',
                'min:2',
                'max:100',
                'regex:/^[a-zA-Z\s]+$/'
            ],
            'date_of_birth' => [
                'required',
                'date',
                'before:' . now()->subYears(18)->format('Y-m-d'),
                'after:' . now()->subYears(100)->format('Y-m-d')
            ],
            'gender' => [
                'required',
                'in:male,female,other'
            ],
            'phone_number' => [
                'required',
                'string',
                'regex:/^(\+92|0)?3\d{9}$/'
            ],
            'email' => [
                'required',
                'email',
                'max:255'
            ],
            'address' => [
                'required',
                'string',
                'min:10',
                'max:500'
            ],
            'city' => [
                'required',
                'string',
                'min:2',
                'max:50',
                'regex:/^[a-zA-Z\s]+$/'
            ],
            'province' => [
                'required',
                'string',
                'in:Punjab,Sindh,Khyber Pakhtunkhwa,Balochistan,Gilgit-Baltistan,Azad Kashmir,Islamabad Capital Territory'
            ],
            'postal_code' => [
                'required',
                'string',
                'regex:/^\d{5}$/'
            ],
            'consent_given' => [
                'required',
                'boolean',
                'accepted'
            ]
        ];

        // Additional rules for updates
        if ($this->isMethod('PUT') || $this->isMethod('PATCH')) {
            // Make some fields optional for updates
            $optionalFields = ['consent_given'];
            foreach ($optionalFields as $field) {
                if (isset($rules[$field])) {
                    $rules[$field] = array_filter($rules[$field], function ($rule) {
                        return $rule !== 'required';
                    });
                    if (!in_array('sometimes', $rules[$field])) {
                        array_unshift($rules[$field], 'sometimes');
                    }
                }
            }
        }

        return $rules;
    }

    /**
     * Get custom validation messages.
     */
    public function messages(): array
    {
        return [
            'cnic.required' => 'CNIC number is required.',
            'cnic.regex' => 'CNIC must be in format: 12345-1234567-1',
            'cnic.unique' => 'This CNIC is already registered.',

            'full_name.required' => 'Full name is required.',
            'full_name.regex' => 'Full name can only contain letters and spaces.',
            'full_name.min' => 'Full name must be at least 2 characters.',
            'full_name.max' => 'Full name cannot exceed 100 characters.',

            'father_name.required' => 'Father\'s name is required.',
            'father_name.regex' => 'Father\'s name can only contain letters and spaces.',
            'father_name.min' => 'Father\'s name must be at least 2 characters.',
            'father_name.max' => 'Father\'s name cannot exceed 100 characters.',

            'date_of_birth.required' => 'Date of birth is required.',
            'date_of_birth.date' => 'Please provide a valid date of birth.',
            'date_of_birth.before' => 'You must be at least 18 years old.',
            'date_of_birth.after' => 'Please provide a valid date of birth.',

            'gender.required' => 'Gender is required.',
            'gender.in' => 'Please select a valid gender.',

            'phone_number.required' => 'Phone number is required.',
            'phone_number.regex' => 'Please provide a valid Pakistani mobile number (e.g., 03001234567).',

            'email.required' => 'Email address is required.',
            'email.email' => 'Please provide a valid email address.',

            'address.required' => 'Address is required.',
            'address.min' => 'Address must be at least 10 characters.',
            'address.max' => 'Address cannot exceed 500 characters.',

            'city.required' => 'City is required.',
            'city.regex' => 'City name can only contain letters and spaces.',
            'city.min' => 'City name must be at least 2 characters.',
            'city.max' => 'City name cannot exceed 50 characters.',

            'province.required' => 'Province is required.',
            'province.in' => 'Please select a valid province.',

            'postal_code.required' => 'Postal code is required.',
            'postal_code.regex' => 'Postal code must be 5 digits.',

            'consent_given.required' => 'You must provide consent to proceed.',
            'consent_given.accepted' => 'You must accept the terms and conditions.'
        ];
    }

    /**
     * Get custom attributes for validator errors.
     */
    public function attributes(): array
    {
        return [
            'cnic' => 'CNIC number',
            'full_name' => 'full name',
            'father_name' => 'father\'s name',
            'date_of_birth' => 'date of birth',
            'phone_number' => 'phone number',
            'postal_code' => 'postal code',
            'consent_given' => 'consent'
        ];
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        // Clean and format CNIC
        if ($this->has('cnic')) {
            $cnic = preg_replace('/[^0-9]/', '', $this->cnic);
            if (strlen($cnic) === 13) {
                $formattedCnic = substr($cnic, 0, 5) . '-' . substr($cnic, 5, 7) . '-' . substr($cnic, 12, 1);
                $this->merge(['cnic' => $formattedCnic]);
            }
        }

        // Clean and format phone number
        if ($this->has('phone_number')) {
            $phone = preg_replace('/[^0-9+]/', '', $this->phone_number);

            // Convert to standard format
            if (strpos($phone, '+92') === 0) {
                $phone = '0' . substr($phone, 3);
            } elseif (strpos($phone, '92') === 0 && strlen($phone) === 12) {
                $phone = '0' . substr($phone, 2);
            }

            $this->merge(['phone_number' => $phone]);
        }

        // Clean names (remove extra spaces)
        if ($this->has('full_name')) {
            $this->merge(['full_name' => trim(preg_replace('/\s+/', ' ', $this->full_name))]);
        }

        if ($this->has('father_name')) {
            $this->merge(['father_name' => trim(preg_replace('/\s+/', ' ', $this->father_name))]);
        }

        // Clean address
        if ($this->has('address')) {
            $this->merge(['address' => trim(preg_replace('/\s+/', ' ', $this->address))]);
        }

        // Clean city name
        if ($this->has('city')) {
            $this->merge(['city' => trim(preg_replace('/\s+/', ' ', $this->city))]);
        }
    }

    /**
     * Configure the validator instance.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Custom validation for CNIC format and checksum
            if ($this->has('cnic') && !$validator->errors()->has('cnic')) {
                if (!$this->isValidCNIC($this->cnic)) {
                    $validator->errors()->add('cnic', 'The CNIC number is invalid.');
                }
            }

            // Validate age based on CNIC (first 6 digits contain date info)
            if (
                $this->has('cnic') && $this->has('date_of_birth') &&
                !$validator->errors()->has('cnic') && !$validator->errors()->has('date_of_birth')
            ) {

                if (!$this->validateCNICDateOfBirth($this->cnic, $this->date_of_birth)) {
                    $validator->errors()->add('date_of_birth', 'Date of birth does not match CNIC information.');
                }
            }

            // Validate gender based on CNIC (last digit indicates gender)
            if (
                $this->has('cnic') && $this->has('gender') &&
                !$validator->errors()->has('cnic') && !$validator->errors()->has('gender')
            ) {

                if (!$this->validateCNICGender($this->cnic, $this->gender)) {
                    $validator->errors()->add('gender', 'Gender does not match CNIC information.');
                }
            }
        });
    }

    /**
     * Validate CNIC number format and checksum
     */
    private function isValidCNIC(string $cnic): bool
    {
        // Remove dashes for calculation
        $cnicDigits = str_replace('-', '', $cnic);

        if (strlen($cnicDigits) !== 13) {
            return false;
        }

        // Validate checksum (simplified algorithm)
        $sum = 0;
        for ($i = 0; $i < 12; $i++) {
            $sum += intval($cnicDigits[$i]) * (($i % 2) + 1);
        }

        $checkDigit = $sum % 10;
        if ($checkDigit !== 0) {
            $checkDigit = 10 - $checkDigit;
        }

        return intval($cnicDigits[12]) === $checkDigit;
    }

    /**
     * Validate date of birth against CNIC
     */
    private function validateCNICDateOfBirth(string $cnic, string $dateOfBirth): bool
    {
        // Extract date from CNIC (positions 1-6: DDMMYY)
        $cnicDigits = str_replace('-', '', $cnic);
        $cnicDay = substr($cnicDigits, 0, 2);
        $cnicMonth = substr($cnicDigits, 2, 2);
        $cnicYear = substr($cnicDigits, 4, 2);

        // Determine century (assume 1900s for years 50-99, 2000s for 00-49)
        $fullYear = intval($cnicYear) >= 50 ? 1900 + intval($cnicYear) : 2000 + intval($cnicYear);

        $cnicDate = sprintf('%04d-%02d-%02d', $fullYear, intval($cnicMonth), intval($cnicDay));

        return $cnicDate === $dateOfBirth;
    }

    /**
     * Validate gender against CNIC
     */
    private function validateCNICGender(string $cnic, string $gender): bool
    {
        // Extract last digit from CNIC
        $cnicDigits = str_replace('-', '', $cnic);
        $lastDigit = intval($cnicDigits[12]);

        // Odd numbers = Male, Even numbers = Female
        $cnicGender = ($lastDigit % 2 === 1) ? 'male' : 'female';

        return $cnicGender === $gender;
    }
}
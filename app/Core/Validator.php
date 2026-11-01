<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Class Validator
 * Kiểm tra tính hợp lệ của dữ liệu đầu vào.
 */
class Validator
{
    /**
     * Xác thực dữ liệu theo tập quy tắc
     *
     * @param array $data Mảng dữ liệu đầu vào
     * @param array $rules Mảng quy tắc ['field' => 'required|numeric|min:0']
     * @return array Danh sách lỗi ['field' => 'Thông báo lỗi']
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $ruleList = explode('|', $ruleString);
            $value = $data[$field] ?? null;

            foreach ($ruleList as $rule) {
                $params = [];
                if (str_contains($rule, ':')) {
                    [$ruleName, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                } else {
                    $ruleName = $rule;
                }

                switch ($ruleName) {
                    case 'required':
                        if ($value === null || $value === '') {
                            $errors[$field] = "Trường {$field} là bắt buộc.";
                        }
                        break;

                    case 'numeric':
                        if ($value !== null && $value !== '' && !is_numeric($value)) {
                            $errors[$field] = "Trường {$field} phải là chữ số.";
                        }
                        break;

                    case 'integer':
                        if ($value !== null && $value !== '' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                            $errors[$field] = "Trường {$field} phải là số nguyên.";
                        }
                        break;

                    case 'in':
                        if ($value !== null && $value !== '' && !in_array((string) $value, $params, true)) {
                            $errors[$field] = "Trường {$field} phải thuộc một trong các giá trị: " . implode(', ', $params);
                        }
                        break;

                    case 'min':
                        $min = (float) ($params[0] ?? 0);
                        if ($value !== null && $value !== '') {
                            if (is_numeric($value) && (float) $value < $min) {
                                $errors[$field] = "Trường {$field} phải có giá trị tối thiểu là {$min}.";
                            } elseif (is_string($value) && mb_strlen($value, 'UTF-8') < $min) {
                                $errors[$field] = "Trường {$field} phải có độ dài tối thiểu {$min} ký tự.";
                            }
                        }
                        break;

                    case 'max':
                        $max = (float) ($params[0] ?? 0);
                        if ($value !== null && $value !== '') {
                            if (is_numeric($value) && (float) $value > $max) {
                                $errors[$field] = "Trường {$field} phải có giá trị tối đa là {$max}.";
                            } elseif (is_string($value) && mb_strlen($value, 'UTF-8') > $max) {
                                $errors[$field] = "Trường {$field} không được vượt quá {$max} ký tự.";
                            }
                        }
                        break;
                }

                if (isset($errors[$field])) {
                    break; // Dừng kiểm tra rule tiếp theo của field này nếu đã có lỗi
                }
            }
        }

        return $errors;
    }

    /**
     * Kiểm tra tọa độ vĩ độ / kinh độ có hợp lệ trên Trái Đất không [-90, 90] / [-180, 180]
     *
     * @param mixed $lat
     * @param mixed $lng
     * @return bool
     */
    public static function isValidCoordinates(mixed $lat, mixed $lng): bool
    {
        if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }

        $fLat = (float) $lat;
        $fLng = (float) $lng;

        return ($fLat >= -90.0 && $fLat <= 90.0 && $fLng >= -180.0 && $fLng <= 180.0);
    }
}

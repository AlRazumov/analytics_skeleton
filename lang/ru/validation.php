<?php

// Минимум для формы входа. Остальные правила берутся из lang/en
// (fallback_locale = en), пока они не понадобятся пользовательским формам.
return [
    'required' => 'Поле «:attribute» обязательно для заполнения.',
    'email' => 'Поле «:attribute» должно быть корректным email-адресом.',
    'string' => 'Поле «:attribute» должно быть строкой.',

    'attributes' => [
        'email' => 'Email',
        'password' => 'Пароль',
    ],
];

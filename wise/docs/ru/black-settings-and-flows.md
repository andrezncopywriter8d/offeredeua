# Black settings и flows

## Что здесь настраивается

Black branch — это логика для разрешённого трафика.

Основные элементы:

- JS Connect action
- JS bot detection
- flows

## JS bot detection

Поддерживаются:

- events
- timeout
- timezone checks

Это дополнительный этап проверки уже на стороне браузера.

## Flows

Flow — это отдельный маршрут трафика в black branch.

Flow включает:

- name
- filters
- steps
- distribution
- optimization settings

## Steps

В step можно задать:

- folders
- redirect URLs
- weights
- load type


#### результат ручного тестировоания ####

импорт xlsx занял 19:35:02 ... 19:36:19

***рекомендации:***
1. не создавай таблцу истории импортов, не трать время на запись данных
2. не создавай лишних полей и индексов. mysql таблица содержит те же поля что исходные
поля из импортируемого файл:
    - external_id	
    - created_at	
    - first_name	
    - last_name	
    - phone	
    - email	
    - city	
    - source	
    - utm_campaign	
    - product	
    - budget_uah	
    - status	
    - manager	
    - comment	
    - next_contact_at

3. счетчики которые выводишь на экран храни в переменных 
4. выведи на экран время потраченное на импорт данных
5. для grid достаточно вывести 
   - external_id	
   - created_at	
   - first_name	
   - last_name	
   - phone	
   - email	

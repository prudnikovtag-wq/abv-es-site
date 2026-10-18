// increment - смещение в пикселях
            // previewwidth - ширина области просмотра. Используем для определения границ скролинга
            // imagesinlist - список из изображений
            // imagesnumber - число картинок в div
            // imagessize[] - размеры каждого изображения
            // imagestotalsize - общая длина всех изображений
            // arrowleft - смещение левой стрелочки
            // arrowright - смещение правой стрелочки
            // lentatime - время прокрутки ленты расчитывается вручную: длина ленты в пикселях * 10
            // lentatimecoef - коэффициент скорости (лучше не делать меньше 5, так как слишком быстро и больше 20, так как зависает скроллинг)
            // refreshIntervalId - место хранения повторения вызова функции прокрутки
            // resizewindow - при перезапуске прокрутки будет понятно, это результат смены размера экрана (true) или по таймеру (false)
            // resizeend - true - завершено изменение экрана
            // resizeendmarker - таймер изменения размера экрана
            var previewwidth = 0,
            previewwidthtemp = 0,
            firstimagewidth = 0,
            firstimageid = "",
            maximagesize = 0,
            imagesinlist,
            imagesnumber,
            imagessize = [],
            imagessid = [],
            imagestotalsize = 0,
            currentimage = 0,
            arrowleft = -25,
            arrowright = 0,
            shift = 0,
            increment = 50,
            lentatime = 0,
            lentatimecoef = 15,
            refreshIntervalId,
            resizewindow = false,
            resizeend,
            resizeendmarker,
            temp,
            fi = document.getElementById('1'),
            pi = document.getElementById('pinfo');


            // при загрузке страницы устанавливаем положение первой картинки в 0 и считаем число картинок в стеке и общую длину ленты
            window.onload = function() {

                // формирую список общего числа картинок
                imagesinlist = document.getElementById('ihost');
                
                // число картинок в div
                imagesnumber = imagesinlist.children;
                
                // размер первой картинки
                firstimagewidth = imagesnumber[0].width + 4;
                
                // id первой картинки
                firstimageid = imagesnumber[0].id;

                // сохраняем размеры картинок в массив и выделяем самую большую из них для ограничения по ней поля прокрутки
                for (let i = 0; i < imagesnumber.length; i++){
                    // ищу самую большую картинку
                    if (maximagesize < imagesnumber[i].width + 4) {
                        maximagesize = imagesnumber[i].width + 4
                    };
                    // считаем общую длину всех изображений
                    imagestotalsize += imagesnumber[i].width + 4;
                }
                
                // ограничиваем максимальную ширину поля для скролинга                
                imagesinlist.style.maxWidth = (imagestotalsize - maximagesize) + 'px';

                // запускаем скролинг рассчитав предварительно размер окна
                scroll();
            };

            // Прокручиваем первую фотку влево
            function scroll() {
                // берём первую картику в списке
                fi = document.getElementById(firstimageid);
                
                // задержка в 2 секунды
                lentatime = 2000;

                // пролистываение со скоростью 1 сек.
                fi.style.transition = 'margin ' + lentatime + 'ms';

                // смещение влево на размер первой картинки
                fi.style.marginLeft = -firstimagewidth + 'px';

                // переупорядочевание списка картинок
                setTimeout (function (){
                    arange();
                }, lentatime);
            }


            // переупорядочевание списка картинок
            function arange() {

                // пролистываение со скоростью 1 сек.
                fi.style.transition = 'margin 0ms';

                // смещение влево на размер первой картинки
                fi.style.marginLeft = '0px';

                // формирую список общего числа картинок
                imagesinlist = document.getElementById('ihost');
                
                // число картинок в div
                imagesnumber = imagesinlist.children;
                
                // переношу первую картинку в конец
                imagesinlist.appendChild(imagesinlist.firstElementChild);
                
                // размер первой картинки
                firstimagewidth = imagesnumber[0].width + 4;
                
                // id первой картинки
                firstimageid = imagesnumber[0].id;

                // снова вызываю слайдер
                scroll();
            
            }
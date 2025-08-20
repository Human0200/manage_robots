/**
 * BG Robots Manager - Управление роботами Bitrix24
 * Основной файл JavaScript для управления роботами
 */

class RobotsManager {
    constructor() {
        this.robots = {
            duplicate: {
                code: 'robot_delete_duplicate',
                name: 'Удалить дубликаты(BG)',
                handler: 'https://bg59.online/Apps/DeleteDuplicate.php',
                statusElement: 'status-duplicate'
            },
            taskResult: {
                code: 'robot_task_result_to_entity',
                name: 'Записать результат задачи в сущность(BG)',
                handler: 'https://bg59.online/Apps/TaskResultToEntity.php',
                statusElement: 'status-task'
            },
            attachContact: {
                code: 'robot_attach_contact_to_lead',
                name: 'Привязать контакт по телефону(BG)',
                handler: 'https://bg59.online/Apps/attachcontacttolead.php',
                statusElement: 'status-attach'
            }
        };
        
        this.init();
    }

    init() {
        this.bindEvents();
        this.checkRobotsStatus();
        this.setupScrollHandler();
    }

    bindEvents() {
        // Общие события
        document.getElementById("ListRobots").addEventListener("click", () => this.listAllRobots());

        // Робот удаления дубликатов
        document.getElementById("createDuplicateButton").addEventListener("click", () => this.createDuplicateRobot());
        document.getElementById("deleteDuplicateButton").addEventListener("click", () => this.deleteRobot('duplicate'));

        // Робот записи результата задачи
        document.getElementById("createTaskResultButton").addEventListener("click", () => this.createTaskResultRobot());
        document.getElementById("deleteTaskResultButton").addEventListener("click", () => this.deleteRobot('taskResult'));

        // Робот привязки контакта
        document.getElementById("createAttachButton").addEventListener("click", () => this.createAttachContactRobot());
        document.getElementById("deleteAttachButton").addEventListener("click", () => this.deleteRobot('attachContact'));
    }

    setupScrollHandler() {
        window.addEventListener('scroll', function() {
            var imageContainer = document.getElementById('image-container');
            if (!imageContainer) return;
            
            var windowHeight = window.innerHeight;
            var windowWidth = window.innerWidth;
            var imageHeight = imageContainer.offsetHeight;
            var imageWidth = imageContainer.offsetWidth;
            var scrollTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
            var scrollLeft = window.pageXOffset || document.documentElement.scrollLeft || document.body.scrollLeft || 0;
            
            imageContainer.style.bottom = (windowHeight - (scrollTop + imageHeight)) + 'px';
            imageContainer.style.right = (windowWidth - (scrollLeft + imageWidth)) + 'px';
        });
    }

    // Проверка статуса роботов
    async checkRobotsStatus() {
        try {
            const robotsList = await this.callBX24Method('bizproc.robot.list', {});
            const installedRobots = robotsList.data();

            Object.keys(this.robots).forEach(key => {
                const robot = this.robots[key];
                const statusElement = document.getElementById(robot.statusElement);
                
                if (installedRobots.includes(robot.code)) {
                    statusElement.textContent = 'Установлен';
                    statusElement.className = 'robot-status installed';
                } else {
                    statusElement.textContent = 'Не установлен';
                    statusElement.className = 'robot-status not-installed';
                }
            });
        } catch (error) {
            console.error('Ошибка при проверке статуса роботов:', error);
        }
    }

    // Обёртка для вызовов BX24 API
    callBX24Method(method, params) {
        return new Promise((resolve, reject) => {
            BX24.callMethod(method, params, function(result) {
                if (result.error()) {
                    reject(result.error());
                } else {
                    resolve(result);
                }
            });
        });
    }

    // Показать уведомление
    showNotification(message, type = 'success') {
        // Создаем простое уведомление
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 8px;
            color: white;
            font-weight: bold;
            z-index: 10000;
            max-width: 300px;
            word-wrap: break-word;
            background: ${type === 'success' ? '#4CAF50' : '#f44336'};
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        `;
        notification.textContent = message;
        
        document.body.appendChild(notification);
        
        setTimeout(() => {
            if (notification.parentNode) {
                notification.parentNode.removeChild(notification);
            }
        }, 5000);
    }

    // Установка/удаление с обновлением статуса
    async installRobot(robotKey, params) {
        try {
            const robot = this.robots[robotKey];
            const result = await this.callBX24Method('bizproc.robot.add', params);
            
            this.showNotification(`Робот "${robot.name}" успешно установлен!`);
            this.updateRobotStatus(robotKey, true);
            
            return result;
        } catch (error) {
            this.showNotification(`Ошибка установки: ${error}`, 'error');
            throw error;
        }
    }

    async deleteRobot(robotKey) {
        try {
            const robot = this.robots[robotKey];
            const params = { 'CODE': robot.code };
            
            await this.callBX24Method('bizproc.robot.delete', params);
            
            this.showNotification(`Робот "${robot.name}" успешно удален!`);
            this.updateRobotStatus(robotKey, false);
        } catch (error) {
            this.showNotification(`Ошибка удаления: ${error}`, 'error');
        }
    }

    updateRobotStatus(robotKey, isInstalled) {
        const robot = this.robots[robotKey];
        const statusElement = document.getElementById(robot.statusElement);
        
        if (isInstalled) {
            statusElement.textContent = 'Установлен';
            statusElement.className = 'robot-status installed';
        } else {
            statusElement.textContent = 'Не установлен';
            statusElement.className = 'robot-status not-installed';
        }
    }

    // Создание робота удаления дубликатов
    async createDuplicateRobot() {
        const params = {
            'CODE': this.robots.duplicate.code,
            'HANDLER': this.robots.duplicate.handler,
            'AUTH_USER_ID': 1,
            'NAME': this.robots.duplicate.name,
            'PROPERTIES': {
                'id_to_keep': {
                    'Name': 'ID элемента для сохранения',
                    'Type': 'int',
                    'Required': 'Y',
                    'Default': 0
                },
                'entity_type': {
                    'Name': 'Тип элемента',
                    'Type': 'select',
                    'Required': 'Y',
                    'Options': {
                        'lead': 'Лид',
                        'deal': 'Сделка'
                    },
                    'Default': 'lead'
                },
                'type_of_delete': {
                    'Name': 'Тип удаления',
                    'Type': 'select',
                    'Required': 'Y',
                    'Options': {
                        'this': 'Этот элемент',
                        'other': 'Все другие'
                    },
                    'Default': 'other'
                }
            }
        };

        return this.installRobot('duplicate', params);
    }

    // Создание робота записи результата задачи
    async createTaskResultRobot() {
        const params = {
            'CODE': this.robots.taskResult.code,
            'HANDLER': this.robots.taskResult.handler,
            'AUTH_USER_ID': 1,
            'NAME': this.robots.taskResult.name,
            'PROPERTIES': {
                'task_id': {
                    'Name': 'ID задачи',
                    'Type': 'int',
                    'Required': 'Y',
                    'Default': 0
                },
                'entity_type': {
                    'Name': 'Тип сущности',
                    'Type': 'select',
                    'Required': 'Y',
                    'Options': {
                        'lead': 'Лид',
                        'contact': 'Контакт', 
                        'company': 'Компания',
                        'deal': 'Сделка',
                        'smart': 'Смарт-процесс'
                    },
                    'Default': 'lead'
                },
                'entity_id': {
                    'Name': 'ID сущности',
                    'Type': 'int',
                    'Required': 'Y',
                    'Default': 0
                },
                'field_code': {
                    'Name': 'Код поля для записи файла',
                    'Type': 'string',
                    'Required': 'Y',
                    'Default': ''
                },
                'smart_process_id': {
                    'Name': 'ID смарт-процесса (только для смарт-процессов)',
                    'Type': 'int',
                    'Required': 'N',
                    'Default': 0
                }
            }
        };

        return this.installRobot('taskResult', params);
    }

    // Создание робота привязки контакта
    async createAttachContactRobot() {
        const params = {
            'CODE': this.robots.attachContact.code,
            'HANDLER': this.robots.attachContact.handler,
            'AUTH_USER_ID': 1,
            'NAME': this.robots.attachContact.name,
            'PROPERTIES': {
                'ID': {
                    'Name': 'ID сущности (лид/сделка)',
                    'Type': 'int',
                    'Required': 'Y',
                    'Default': 0
                },
                'Phone': {
                    'Name': 'Номер телефона для поиска контакта',
                    'Type': 'string',
                    'Required': 'Y',
                    'Default': ''
                },
                'entity_type': {
                    'Name': 'Тип сущности',
                    'Type': 'select',
                    'Required': 'Y',
                    'Options': {
                        'lead': 'Лид',
                        'deal': 'Сделка'
                    },
                    'Default': 'lead'
                }
            }
        };

        return this.installRobot('attachContact', params);
    }

    // Показать список всех роботов
    async listAllRobots() {
        try {
            const result = await this.callBX24Method('bizproc.robot.list', {});
            const robotsList = result.data();
            
            if (robotsList && robotsList.length > 0) {
                const message = `Установленные роботы:\n${robotsList.join('\n')}`;
                this.showNotification(message);
            } else {
                this.showNotification('Список роботов пуст.');
            }
        } catch (error) {
            this.showNotification(`Ошибка получения списка роботов: ${error}`, 'error');
        }
    }
}

// Инициализация приложения после загрузки DOM
document.addEventListener('DOMContentLoaded', function() {
    // Ждем загрузки BX24 API
    if (typeof BX24 !== 'undefined') {
        new RobotsManager();
    } else {
        // Если BX24 еще не загружен, ждем
        const checkBX24 = setInterval(() => {
            if (typeof BX24 !== 'undefined') {
                clearInterval(checkBX24);
                new RobotsManager();
            }
        }, 100);
    }
});
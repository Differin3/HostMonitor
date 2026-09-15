#ifndef NET_RX_H
#define NET_RX_H

#include <stddef.h>

void net_stack_init(void);
void net_process(void);

/* Loopback: положить локально сформированный IP-пакет в очередь
   (синхронный вызов приводит к переполнению стека). */
int net_deliver_local(const void* ip_pkt, size_t len);

/* Обработать накопившиеся loopback-пакеты. Вызывать из цикла ожидания
   соединения и из net_process. */
void net_drain_loopback(void);

/* Запускает выделенную kernel-задачу, которая постоянно вызывает
   net_process() (единственный штатный поллер RX). */
void net_start_poller(void);

#endif

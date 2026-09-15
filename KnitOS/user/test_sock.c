#include "syscall.h"

int main(void) {
    int fd = sys_socket(2, 1, 0);
    if (fd < 0) { sys_write(1, "socket fail\n", 11); sys_exit(1); }
    struct sockaddr_in a;
    a.sin_family = 2;
    a.sin_port = 0x5000;
    a.sin_addr = 0x010aa8c0;
    int r = sys_connect(fd, &a, 16);
    if (r < 0) { sys_write(1, "connect fail\n", 12); }
    else { sys_write(1, "connect ok\n", 10); }
    sys_close(fd);
    sys_write(1, "done\n", 5);
    sys_exit(0);
    return 0;
}

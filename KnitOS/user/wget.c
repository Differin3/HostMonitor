// wget — простой HTTP-клиент: скачивает URL в файл или на stdout.
// Использование: wget <url> [outfile]
#include "lib/libk.h"

/* ---- распаковать "a.b.c.d" -> uint32, 1 при успехе ---- */
static int parse_ip(const char* s, unsigned* a, unsigned* b, unsigned* c, unsigned* d) {
    *a = *b = *c = *d = 0;
    const char* p = s;
    for (int i = 0; i < 4; i++) {
        if (i > 0) {
            if (*p != '.') return 0;
            p++;
        }
        if (*p < '0' || *p > '9') return 0;
        unsigned v = 0;
        int digits = 0;
        while (*p >= '0' && *p <= '9' && digits < 3) {
            v = v * 10 + (*p - '0');
            p++;
            digits++;
        }
        if (digits == 0) return 0;
        if (v > 255) return 0;
        if (i == 0) *a = v; else if (i == 1) *b = v; else if (i == 2) *c = v; else *d = v;
    }
    return 1;
}

/* ---- мини-парсер URL: http://host[:port]/path ---- */
static int parse_url(const char* url, char* host, int* port, char* path) {
    const char* p = url;
    if (strncmp(p, "http://", 7) != 0) return -1;
    p += 7;

    const char* slash = strchr(p, '/');
    const char* colon = strchr(p, ':');

    if (colon && (!slash || colon < slash)) {
        int hlen = (int)(colon - p);
        memcpy(host, p, hlen);
        host[hlen] = 0;
        *port = atoi(colon + 1);
    } else {
        int hlen = slash ? (int)(slash - p) : (int)strlen(p);
        memcpy(host, p, hlen);
        host[hlen] = 0;
        *port = 80;
    }

    if (slash) strcpy(path, slash);
    else       strcpy(path, "/");
    return 0;
}

/* ---- DNS через /etc/hosts ---- */
static uint32_t resolve_host(const char* host) {
    unsigned a=0,b=0,c=0,d=0;
    if (parse_ip(host, &a,&b,&c,&d))
        return (a<<24)|(b<<16)|(c<<8)|d;

    int fd = (int)sys_open("/etc/hosts", 0x0001, 0);
    if (fd < 0) return 0;
    char buf[4096];
    long n = sys_read(fd, buf, sizeof(buf) - 1);
    sys_close(fd);
    if (n <= 0) return 0;
    buf[n] = 0;

    char* line = buf;
    while (*line) {
        char* eol = strchr(line, '\n');
        if (eol) *eol = 0;

        char* s = line;
        while (*s == ' ' || *s == '\t') s++;
        if (*s == '#' || *s == 0) { line = eol ? eol + 1 : line + strlen(line); continue; }

        char ipstr[64], h[256];
        int ni = 0, hi = 0;
        while (*s && *s != ' ' && *s != '\t' && ni < 63) ipstr[ni++] = *s++;
        ipstr[ni] = 0;
        while (*s == ' ' || *s == '\t') s++;
        while (*s && *s != ' ' && *s != '\t' && hi < 255) h[hi++] = *s++;
        h[hi] = 0;

        if (strcmp(h, host) == 0) {
            unsigned a2=0,b2=0,c2=0,d2=0;
            if (parse_ip(ipstr, &a2,&b2,&c2,&d2))
                return (a2<<24)|(b2<<16)|(c2<<8)|d2;
        }
        line = eol ? eol + 1 : line + strlen(line);
    }
    return 0;
}

int main(int argc, char** argv) {
    if (argc < 2) {
        puts("usage: wget <url> [outfile]");
        sys_exit(1);
    }
    const char* url = argv[1];
    const char* outfile = argc > 2 ? argv[2] : 0;

    char host[128], path[256];
    int port = 80;
    if (parse_url(url, host, &port, path) != 0) {
        printf("wget: bad url '%s'\r\n", url);
        sys_exit(1);
    }

    uint32_t ip = resolve_host(host);
    if (ip == 0) {
        printf("wget: cannot resolve %s\r\n", host);
        sys_exit(1);
    }

    int fd = (int)sys_socket(AF_INET, SOCK_STREAM, 0);
    if (fd < 0) { puts("wget: socket failed"); sys_exit(1); }

    struct sockaddr_in addr;
    addr.sin_family = AF_INET;
    addr.sin_port = (unsigned short)port; /* ядро само делает htons */
    addr.sin_addr = ip;

    printf("Connecting to %s:%d ...\r\n", host, port);
    if (sys_connect(fd, &addr, 10000) < 0) {
        puts("wget: connect failed"); sys_sock_close(fd); sys_exit(1);
    }

    char req[512];
    int rlen = snprintf(req, sizeof(req),
        "GET %s HTTP/1.0\r\nHost: %s\r\n"
        "User-Agent: KnitOS-wget/0.1\r\nConnection: close\r\n\r\n",
        path, host);
    if (sys_send(fd, req, (unsigned long)rlen) < 0) {
        puts("wget: send failed"); sys_sock_close(fd); sys_exit(1);
    }

    int outfd = -1;
    if (outfile) {
        outfd = (int)sys_open(outfile, 0x0102, 0644); /* O_WRONLY|O_CREAT|O_TRUNC */
        if (outfd < 0) {
            printf("wget: cannot open '%s'\r\n", outfile);
            sys_sock_close(fd); sys_exit(1);
        }
    }

    char buf[4096];
    long total = 0;
    int in_body = 0;

    for (;;) {
        int n = (int)sys_recv(fd, buf, sizeof(buf), 3000);
        if (n <= 0) break;
        if (!in_body) {
            char* body = strstr(buf, "\r\n\r\n");
            if (body) { in_body = 1; body += 4; n -= (int)(body - buf); }
            else continue;
        }
        if (outfd >= 0) {
            if (sys_write(outfd, buf, (unsigned long)n) < 0) { puts("wget: write error"); break; }
        } else {
            sys_write(1, buf, (unsigned long)n);
        }
        total += n;
    }
    printf("wget: %ld bytes received\r\n", total);
    if (outfd >= 0) sys_close(outfd);
    sys_sock_close(fd);
    sys_exit(0);
}

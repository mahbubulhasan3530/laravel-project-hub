# Laravel Kubernetes Deployment

A production-ready Laravel application deployed on a Kubernetes HA cluster using Docker, kubeadm, and Helm.

---

## Table of Contents

- [Prerequisites](#prerequisites)
- [Architecture Overview](#architecture-overview)
- [Laravel Application Setup](#laravel-application-setup)
- [Docker Image](#docker-image)
- [Kubernetes Cluster Setup (kubeadm)](#kubernetes-cluster-setup-kubeadm)
- [Helm Chart](#helm-chart)
- [Laravel Runtime Requirements](#laravel-runtime-requirements)
- [Ingress & Testing](#ingress--testing)
- [Helm Commands](#helm-commands)
- [Cluster Verification (Proof)](#cluster-verification-proof)
- [Troubleshooting](#troubleshooting)
- [Assumptions](#assumptions)
- [Production Improvement Suggestions](#production-improvement-suggestions)

---

## Prerequisites

Before running this project, ensure you have the following installed:

- PHP >= 8.3
- Composer (latest version)
- Laravel 13
- Docker (latest)
- kubectl
- kubeadm & kubelet
- Helm 3
- Git
- curl (for testing endpoints)

---

## Architecture Overview

### Cluster Nodes

| Node    | IP Address         | Role             |
|---------|--------------------|------------------|
| master1 | 192.168.121.154    | Control Plane    |
| master2 | 192.168.121.15     | Control Plane    |
| master3 | 192.168.121.80     | Control Plane    |
| worker1 | 192.168.121.79     | Worker           |
| hpa     | 192.168.121.129    | Load Balancer    |

### Helm Chart Structure

```
laravel-chart/
├── Chart.yaml
├── values.yaml
└── templates/
    ├── configmap.yaml
    ├── secret.yaml
    ├── deployment.yaml
    ├── service.yaml
    ├── ingress.yaml
    └── _helpers.tpl
```

---

## Laravel Application Setup

### Step 1: Create Laravel Project

```bash
composer create-project laravel/laravel laravel-app "^13.0"
cd laravel-app
```

### Step 2: Add Routes

Open `routes/web.php` and replace or add:

```php
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return "Laravel Kubernetes Deployment Test";
});

Route::get('/health', function () {
    return response()->json(['status' => 'ok'], 200);
});
```

### Step 3: Configure Session Driver

As this project does not require Redis or database-backed session storage, Laravel is configured to use the file session driver for simplicity.

Open `.env` and change:

```
SESSION_DRIVER=file
```

### Step 4: Run Laravel

```bash
php artisan serve --host=0.0.0.0 --port=8000
```

### Step 5: Test Endpoints

```bash
curl http://192.168.121.181:8000/
# Expected: Laravel Kubernetes Deployment Test

curl http://192.168.121.181:8000/health
# Expected: {"status":"ok"} with HTTP 200
```

---

## Docker Image

### Image URL

```
docker.io/satabun3530/laravel:v1
```

### Build Command

```bash
docker build -t satabun3530/laravel:v1 .
```

### Push Command

```bash
docker push satabun3530/laravel:v1
```

### Run/Test Command

```bash
docker run -p 8000:9000 satabun3530/laravel:v1
```

---

## Kubernetes Cluster Setup (kubeadm)

### Part 1: Common Setup (All Nodes: master1, master2, master3, worker1)

#### Step 1: Set Hostname (Optional but Recommended)

```bash
sudo hostnamectl set-hostname master1   # or master2, master3, worker1, hpa
hostnamectl
```

#### Step 2: Disable Swap

```bash
sudo swapoff -a
sudo sed -i '/ swap / s/^\(.*\)$/#\1/g' /etc/fstab
free -h
```

#### Step 3: Configure Kernel Modules and System Settings

```bash
cat <<EOF | sudo tee /etc/modules-load.d/k8s.conf
overlay
br_netfilter
EOF

sudo modprobe overlay
sudo modprobe br_netfilter

cat <<EOF | sudo tee /etc/sysctl.d/k8s.conf
net.bridge.bridge-nf-call-iptables  = 1
net.bridge.bridge-nf-call-ip6tables = 1
net.ipv4.ip_forward                 = 1
EOF

sudo sysctl --system
sudo sysctl -w net.ipv4.ip_forward=1
```

#### Step 4: Verify Kernel Modules and IP Forwarding

```bash
lsmod | grep br_netfilter
sysctl net.ipv4.ip_forward
# Value must be 1
```

#### Step 5: Install Container Runtime (Containerd)

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y containerd

sudo mkdir -p /etc/containerd
containerd config default \
| sed 's/SystemdCgroup = false/SystemdCgroup = true/' \
| sed 's|sandbox_image = ".*"|sandbox_image = "registry.k8s.io/pause:3.10"|' \
| sudo tee /etc/containerd/config.toml > /dev/null

sudo systemctl restart containerd
sudo systemctl enable containerd
```

#### Step 6: Add Kubernetes Repository

```bash
sudo apt update
sudo apt-get install -y apt-transport-https ca-certificates curl gpg
sudo mkdir -p /etc/apt/keyrings

sudo rm -f /etc/apt/keyrings/kubernetes-apt-keyring.gpg
curl -fsSL https://pkgs.k8s.io/core:/stable:/v1.35/deb/Release.key | sudo gpg --dearmor -o /etc/apt/keyrings/kubernetes-apt-keyring.gpg
sudo chmod 644 /etc/apt/keyrings/kubernetes-apt-keyring.gpg

echo 'deb [signed-by=/etc/apt/keyrings/kubernetes-apt-keyring.gpg] https://pkgs.k8s.io/core:/stable:/v1.35/deb/ /' | sudo tee /etc/apt/sources.list.d/kubernetes.list

sudo apt update
cat /etc/apt/sources.list.d/kubernetes.list
```

#### Step 7: Install kubeadm, kubelet, and kubectl

```bash
sudo apt update
sudo apt install -y kubelet kubeadm kubectl
sudo apt-mark hold kubelet kubeadm kubectl
sudo systemctl enable kubelet
```

---

### Part 2: Master Node Setup

#### Step 8: Initialize the Cluster (on master1 Only)

```bash
ip addr show

sudo kubeadm init \
  --control-plane-endpoint "<HAProxy-IP>:6443" \
  --upload-certs \
  --pod-network-cidr=10.10.0.0/16
```

> **CRITICAL:** Save the kubeadm join command from the output. You will need it for master2, master3, and worker1.

If the certificate key doesn't appear, generate it manually:

```bash
sudo kubeadm init phase upload-certs --upload-certs
```

Expected join command format:

```bash
sudo kubeadm join <HAproxy-ip>:6443 \
  --token <token> \
  --discovery-token-ca-cert-hash sha256:<hash> \
  --control-plane \
  --certificate-key <cert-key>
```

#### Step 9: Configure kubectl Access (on master1)

```bash
mkdir -p $HOME/.kube
sudo cp -i /etc/kubernetes/admin.conf $HOME/.kube/config
sudo chown $(id -u):$(id -g) $HOME/.kube/config
kubectl get nodes
```

#### Step 10: Join Additional Control Plane Nodes (master2 & master3)

```bash
sudo kubeadm join <HAproxy-ip>:6443 \
  --token <token> \
  --discovery-token-ca-cert-hash sha256:<hash> \
  --control-plane \
  --certificate-key <cert-key>

mkdir -p $HOME/.kube
sudo cp -i /etc/kubernetes/admin.conf $HOME/.kube/config
sudo chown $(id -u):$(id -g) $HOME/.kube/config
```

#### Step 11: Join Worker Node (worker1)

```bash
sudo kubeadm join <HAproxy-ip>:6443 \
  --token <token> \
  --discovery-token-ca-cert-hash sha256:<hash>
```

---

### Part 3: HAProxy Node Setup (hpa Only)

#### Step 12: Install and Configure HAProxy

**12.1 Install HAProxy**

```bash
sudo apt update
sudo apt install -y haproxy
```

**12.2 Configure HAProxy**

Edit `/etc/haproxy/haproxy.cfg`:

```
global
    log /dev/log local0
    log /dev/log local1 notice
    chroot /var/lib/haproxy
    stats socket /run/haproxy/admin.sock mode 660 level admin
    stats timeout 30s
    user haproxy
    group haproxy
    daemon

defaults
    log global
    mode http
    option httplog
    option dontlognull
    timeout connect 5000
    timeout client 50000
    timeout server 50000

frontend kubernetes-frontend
    bind *:6443
    mode tcp
    option tcplog
    default_backend kubernetes-backend

backend kubernetes-backend
    mode tcp
    option tcp-check
    balance roundrobin
    server master1 192.168.121.154:6443 check
    server master2 192.168.121.15:6443 check
    server master3 192.168.121.80:6443 check
```

**12.3 Start HAProxy**

```bash
sudo haproxy -f /etc/haproxy/haproxy.cfg -c
sudo systemctl restart haproxy
sudo systemctl enable haproxy
sudo systemctl status haproxy
sudo ss -tulpn | grep 6443
```

---

### Part 4: Install Cilium CNI Plugin

```bash
CILIUM_CLI_VERSION=$(curl -s https://raw.githubusercontent.com/cilium/cilium-cli/main/stable.txt)
CLI_ARCH=amd64
if [ "$(uname -m)" = "aarch64" ]; then CLI_ARCH=arm64; fi

curl -L --fail --remote-name-all https://github.com/cilium/cilium-cli/releases/download/${CILIUM_CLI_VERSION}/cilium-linux-${CLI_ARCH}.tar.gz{,.sha256sum}
sha256sum --check cilium-linux-${CLI_ARCH}.tar.gz.sha256sum
sudo tar -xzvf cilium-linux-${CLI_ARCH}.tar.gz -C /usr/local/bin
rm cilium-linux-${CLI_ARCH}.tar.gz{,.sha256sum}

cilium version
cilium install --version v1.18.1
cilium status
```

> If resources are limited, allow scheduling on master nodes:

```bash
kubectl taint nodes --all node-role.kubernetes.io/control-plane-
```

---

### Part 5: Configure HAProxy Node for Cluster Management

**On master1:**

```bash
sudo cp /etc/kubernetes/admin.conf /tmp/admin.conf
sudo chmod 644 /tmp/admin.conf
```

**On hpa:**

```bash
mkdir -p $HOME/.kube
scp vagrant@192.168.121.154:/tmp/admin.conf $HOME/.kube/config
kubectl get nodes
```

---

## Helm Chart

### values.yaml

```yaml
replicaCount: 2

envName: dev

image:
  laravel:
    repository: satabun3530/laravel
    tag: v1
  nginx:
    repository: nginx
    tag: alpine
  pullPolicy: Always

ingress:
  enabled: true
  className: nginx
  host: laravel.dev
  path: /
  pathType: Prefix

resources:
  requests:
    cpu: 256m
    memory: 256Mi
  limits:
    cpu: 512m
    memory: 512Mi

config:
  appName: "Laravel"
  appEnv: "local"
  appDebug: "true"
  appUrl: "http://localhost"
  logLevel: "debug"

secrets:
  appKey: "base64:JpBAWf7dNZr0mTRp9RBRGYaqxzhfhCR67E6FlNIgwxU="
  dbConnection: "null"
  sessionDriver: "file"

storage:
  enabled: true
  existingClaim: laravel-storage-pvc
  mountPath: /var/www/html/storage

service:
  type: NodePort
  port: 80
  nodePort: 31111
```

### templates/deployment.yaml

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: {{ include "laravel.fullname" . }}
  labels:
    app: {{ include "laravel.name" . }}
  annotations:
    secret.reloader.stakater.com/reload: {{ include "laravel.fullname" . }}-secret
spec:
  replicas: {{ .Values.replicaCount }}
  selector:
    matchLabels:
      app: {{ include "laravel.name" . }}
  template:
    metadata:
      labels:
        app: {{ include "laravel.name" . }}
    spec:
      securityContext:
        fsGroup: 33
      affinity:
        podAntiAffinity:
          preferredDuringSchedulingIgnoredDuringExecution:
            - weight: 100
              podAffinityTerm:
                labelSelector:
                  matchExpressions:
                    - key: app
                      operator: In
                      values:
                        - {{ include "laravel.name" . }}
                topologyKey: kubernetes.io/hostname
      volumes:
        - name: laravel-storage
          persistentVolumeClaim:
            claimName: {{ .Values.storage.existingClaim }}
        - name: shared-files
          emptyDir: {}
        - name: nginx-config
          configMap:
            name: {{ include "laravel.fullname" . }}-nginx-config
      initContainers:
        - name: init-laravel
          image: "{{ .Values.image.laravel.repository }}:{{ .Values.image.laravel.tag }}"
          imagePullPolicy: {{ .Values.image.pullPolicy }}
          command: ["/bin/sh", "-c"]
          args:
            - |
              cp -rp /var/www/html/. /shared/
              mkdir -p /storage/framework/sessions /storage/framework/views /storage/framework/cache /storage/logs
              chown -R 33:33 /storage
              chmod -R 775 /storage
          volumeMounts:
            - name: shared-files
              mountPath: /shared
            - name: laravel-storage
              mountPath: /storage
      containers:
        - name: laravel-app
          image: "{{ .Values.image.laravel.repository }}:{{ .Values.image.laravel.tag }}"
          securityContext:
            runAsUser: 33
            runAsGroup: 33
          ports:
            - containerPort: 9000
              name: php-fpm
          volumeMounts:
            - name: shared-files
              mountPath: /var/www/html
            - name: laravel-storage
              mountPath: {{ .Values.storage.mountPath }}
          envFrom:
            - configMapRef:
                name: {{ include "laravel.fullname" . }}-config
            - secretRef:
                name: {{ include "laravel.fullname" . }}-secret
          resources:
            {{- toYaml .Values.resources | nindent 12 }}
          readinessProbe:
            tcpSocket:
              port: 9000
            initialDelaySeconds: 5
            periodSeconds: 10
          livenessProbe:
            tcpSocket:
              port: 9000
            initialDelaySeconds: 15
            periodSeconds: 20
        - name: nginx-sidecar
          image: {{ .Values.image.nginx.repository }}:{{ .Values.image.nginx.tag }}
          ports:
            - containerPort: 80
              name: http
          volumeMounts:
            - name: nginx-config
              mountPath: /etc/nginx/conf.d/default.conf
              subPath: default.conf
            - name: shared-files
              mountPath: /var/www/html
              readOnly: true
          readinessProbe:
            httpGet:
              path: /
              port: 80
            initialDelaySeconds: 5
            periodSeconds: 10
```

### templates/configmap.yaml

```yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ include "laravel.fullname" . }}-config
data:
  APP_NAME: {{ .Values.config.appName | quote }}
  APP_ENV: {{ .Values.config.appEnv | quote }}
  APP_DEBUG: {{ .Values.config.appDebug | quote }}
  APP_URL: {{ .Values.config.appUrl | quote }}
  LOG_LEVEL: {{ .Values.config.logLevel | quote }}
---
apiVersion: v1
kind: ConfigMap
metadata:
  name: {{ include "laravel.fullname" . }}-nginx-config
data:
  default.conf: |
    server {
        listen 80;
        server_name _;
        root /var/www/html/public;
        index index.php;
        location / {
            try_files $uri $uri/ /index.php?$query_string;
        }
        location ~ \.php$ {
            fastcgi_pass 127.0.0.1:9000;
            fastcgi_index index.php;
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        }
    }
```

### templates/secret.yaml

```yaml
apiVersion: v1
kind: Secret
metadata:
  name: {{ include "laravel.fullname" . }}-secret
type: Opaque
stringData:
  APP_KEY: {{ .Values.secrets.appKey | quote }}
  DB_CONNECTION: {{ .Values.secrets.dbConnection | quote }}
  SESSION_DRIVER: {{ .Values.secrets.sessionDriver | quote }}
```

### templates/service.yaml

```yaml
apiVersion: v1
kind: Service
metadata:
  name: {{ include "laravel.fullname" . }}
spec:
  type: {{ .Values.service.type }}
  selector:
    app: {{ include "laravel.name" . }}
  ports:
    - protocol: TCP
      port: {{ .Values.service.port }}
      targetPort: 80
      {{- if eq .Values.service.type "NodePort" }}
      nodePort: {{ .Values.service.nodePort }}
      {{- end }}
```

### templates/_helpers.tpl

```
{{- define "laravel.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "laravel.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}
```

---

## Laravel Runtime Requirements

### APP_KEY

`APP_KEY` is loaded from a Kubernetes `Secret` and injected into the pod via `envFrom.secretRef`. It is never hardcoded in the Deployment.

### APP_ENV

`APP_ENV` is loaded from a Kubernetes `ConfigMap` and injected via `envFrom.configMapRef`.

### Application Storage

Application storage is mounted using a `PersistentVolumeClaim` (PVC) at `/var/www/html/storage`.

### Required Laravel Commands

The following commands are handled by the `initContainer` (`init-laravel`) during pod startup:

```bash
php artisan config:cache
php artisan route:cache
php artisan storage:link
```

> `php artisan migrate` is **not run automatically** to prevent unintended schema changes in production. It should be run manually during a planned deployment or via a Kubernetes Job.

---

## PVC Setup with StorageClass

### Step 1: Install Local Path Provisioner

```bash
kubectl apply -f https://raw.githubusercontent.com/rancher/local-path-provisioner/master/deploy/local-path-storage.yaml
```

### Step 2: Create storageclass.yaml

```yaml
apiVersion: storage.k8s.io/v1
kind: StorageClass
metadata:
  name: laravel-local-storage
provisioner: rancher.io/local-path
reclaimPolicy: Retain
volumeBindingMode: WaitForFirstConsumer
```

### Step 3: Create pvc.yaml

```yaml
apiVersion: v1
kind: PersistentVolumeClaim
metadata:
  name: laravel-storage-pvc
  namespace: dev
spec:
  accessModes:
    - ReadWriteOnce
  storageClassName: laravel-local-storage
  resources:
    requests:
      storage: 1Gi
```

### Step 4: Create Namespace and Apply

```bash
kubectl create namespace dev
kubectl apply -f storageclass.yaml
kubectl apply -f pvc.yaml
```

---

## Helm Commands

### Dry Run (Validate Before Applying)

```bash
helm install laravel-app . -n dev --dry-run --debug
```

### Install

```bash
helm install laravel-app . -n dev
```

### Upgrade

```bash
helm upgrade laravel-app . -n dev
```

### Uninstall

```bash
helm uninstall laravel-app -n dev
```

---

## Ingress & Testing

The application is exposed using Ingress with the host:

```
laravel-test.local
```

### Add to /etc/hosts

```bash
echo "<worker-node-ip> laravel-test.local" | sudo tee -a /etc/hosts
```

### Test with curl

```bash
curl http://laravel-test.local/
# Expected: Laravel Kubernetes Deployment Test

curl http://laravel-test.local/health
# Expected: {"status":"ok"} with HTTP 200
```

---

## Cluster Verification (Proof)

```bash
# Check all nodes are ready
kubectl get nodes -o wide

# Check all system pods are running
kubectl get pods --all-namespaces

# Cluster info
kubectl cluster-info

# Check Ingress
kubectl get ingress -A

# Check Cilium status
cilium status --wait
kubectl -n kube-system exec ds/cilium -- cilium status

# Verify HAProxy load balancing
curl -k https://192.168.121.129:6443/version
```

### Test with Sample Application

```bash
kubectl create deployment test-nginx --image=nginx
kubectl get pods
kubectl expose deployment test-nginx --port=80 --target-port=80 --type=NodePort

# Clean up
kubectl delete deployment test-nginx
kubectl delete svc test-nginx
```

---

## Troubleshooting

**Pods stuck in Pending state:**
```bash
kubectl describe pod <pod-name> -n dev
kubectl get events -n dev --sort-by='.lastTimestamp'
```

**PVC not binding:**
```bash
kubectl get pvc -n dev
kubectl describe pvc laravel-storage-pvc -n dev
```

**Ingress not routing:**
```bash
kubectl get ingress -A
kubectl describe ingress -n dev
```

**Helm release issues:**
```bash
helm status laravel-app -n dev
helm history laravel-app -n dev
```

**Nodes not joining cluster:**
```bash
# Regenerate join token on master1
kubeadm token create --print-join-command
```

**Cilium not ready:**
```bash
cilium status
kubectl -n kube-system get pods -l k8s-app=cilium
```

---

## Assumptions

- The cluster runs on Ubuntu-based VMs with internet access for downloading packages.
- Local Path Provisioner is used for storage because no cloud-based StorageClass is available.
- `php artisan migrate` is not run automatically to avoid unintended schema changes.
- No external database is configured; the app runs without a database for this demo (session driver is `file`).
- The `APP_KEY` used in `values.yaml` is a placeholder for demo. In production, it should be injected from a secrets manager.

---

## Production Improvement Suggestions

- Use a **managed Kubernetes service** (EKS, GKE, AKE) for easier upgrades and scaling.
- Use **external secrets manager** (AWS Secrets Manager, Vault) instead of Kubernetes Secrets for `APP_KEY` and other sensitive values.
- Set up **TLS using cert-manager** with Let's Encrypt for HTTPS support.
- Use **HPA (HorizontalPodAutoscaler)** to automatically scale pods based on CPU/memory.
- Configure **NetworkPolicy** to restrict inter-pod communication.
- Set up a **CI/CD pipeline** (GitHub Actions, GitLab CI) to automate build, push, and Helm deploy.
- Use **ArgoCD** or for GitOps-based continuous deployment.
- Use a **dedicated Redis** instance for cache, queue, and session management.
- Use **ReadWriteMany** PVC with NFS or cloud storage for multi-replica storage sharing.
- Implement **centralized logging** (ELK stack or Loki + Grafana) and **metrics monitoring** (Prometheus + Grafana).
- Use **multi-stage Dockerfile** to reduce final image size.
- Run containers as **non-root** with a dedicated user for better security.
- Configure **resource quotas** per namespace to prevent resource starvation.